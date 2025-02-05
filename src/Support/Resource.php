<?php

namespace PragmaRX\Health\Support;

use Illuminate\Support\Collection;
use PragmaRX\Health\Commands;
use Illuminate\Support\Str;
use JsonSerializable;
use PragmaRX\Health\Events\RaiseHealthIssue;
use PragmaRX\Health\Support\Traits\ImportProperties;
use PragmaRX\Health\Support\Traits\ToArray;
use Ramsey\Uuid\Uuid;

class Resource implements JsonSerializable
{
    use ToArray, ImportProperties;

    /**
     * @var string
     */
    public $id;

    /**
     * @var string
     */
    public $name;

    /**
     * @var string
     */
    public $slug;

    /**
     * @var string
     */
    public $abbreviation;

    /**
     * @var bool
     */
    public $isGlobal;

    /**
     * @var string
     */
    public $errorMessage;

    /**
     * @var array
     */
    public $style;

    /**
     * @var bool
     */
    public $notify;

    /**
     * @var Collection
     */
    public $targets;

    /**
     * @var ResourceChecker
     */
    public $checker;

    /**
     * @var Collection
     */
    public $resources;

    /**
     * @var bool
     */
    protected $notified;

    /**
     * @var string
     */
    protected $currentAction;

    /**
     * @var bool|null
     */
    protected $graphEnabled = null;

    /**
     * Resource factory.
     *
     * @param Collection $data
     * @return resource
     * @throws \Exception
     */
    public static function factory(Collection $data)
    {
        $instance = new static();

        $instance->id = (string) Uuid::uuid4();

        $instance->name = $data['name'];

        $instance->slug = Str::slug($data['name']);

        $instance->graphEnabled = isset($data['graph_enabled'])
            ? $data['graph_enabled']
            : null;

        $instance->abbreviation = $data['abbreviation'];

        $instance->targets = $instance->instantiateTargets(
            $data['targets'] ?? collect()
        );

        $instance->notify =
            $data['notify'] ?? config('health.notifications.enabled');

        $instance->style = $instance->keysToCamel(config('health.style'));

        $instance->style['columnSize'] =
            $data['column_size'] ?? $instance->style['columnSize'];

        $instance->errorMessage =
            $data['error_message'] ?? config('health.errors.message');

        $instance->isGlobal = $data['is_global'] ?? false;

        $instance->checker = $instance->instantiateChecker($data['checker']);

        $instance->importProperties($data);

        return $instance;
    }

    /**
     * Instantiate all checkers for a resource.
     *
     * @param Collection $targets
     * @return Collection|\IlluminateAgnostic\Arr\Support\Collection|\IlluminateAgnostic\Collection\Support\Collection|\IlluminateAgnostic\Str\Support\Collection|mixed|\Tightenco\Collect\Support\Collection|\Vanilla\Support\Collection
     */
    public function instantiateTargets(Collection $targets)
    {
        if ($targets->isEmpty()) {
            return collect([Target::factory($this, $targets)]);
        }

        $current = collect();

        $targets = $targets
            ->map(function (Collection $targetList) {
                return $targetList->map(function ($target, $name) {
                    return Target::factory($this, $target, $name);
                });
            })
            ->reduce(function ($current, $targetList) {
                foreach ($targetList as $target) {
                    $current[] = $target;
                }

                return $current;
            }, $current);

        return $targets;
    }

    /**
     * Instantiate one checker.
     *
     * @param string $checker
     * @return object
     */
    public function instantiateChecker(string $checker)
    {
        return instantiate($checker);
    }

    /**
     * Check all targets for a resource.
     *
     * @param string $action
     * @return resource
     */
    public function check($action = 'resource')
    {
        $this->setCurrentAction($action)->targets->each(function (
            Target $target
        ) {
            $target->check($target);
        });

        $this->notify();

        return $this;
    }

    /**
     * Check global resources.
     *
     * @param $resources
     * @return resource
     */
    public function checkGlobal($resources)
    {
        return $this->setResources($resources)->check();
    }

    /**
     * Check if is healthy.
     *
     * @return mixed
     */
    public function isHealthy()
    {
        return $this->targets->reduce(function ($carry, $target) {
            return $carry && $target->result->healthy;
        }, true);
    }

    /**
     * Check status of the resource (overall)
     *
     * @return mixed
     */
    public function getStatus()
    {
        $exitCode = Commands::EXIT_CODES[result::OK];
        $this->targets->each(function ($target) use(&$exitCode) {
            // Handles exit codes based on the result's status.
            $thisStatus = $target->result ? $target->result->getStatus() : Result::UNKNOWN;
            $thisExitCode = Commands::EXIT_CODES[$thisStatus];
            // An exit code with a greater value should be preferred as the output.
            if ($thisExitCode > $exitCode) {
                $exitCode = $thisExitCode;
            }
        });

        return array_flip(Commands::EXIT_CODES)[$exitCode];
    }

    /**
     * Returns the summary details, such as errors, etc to be listed with the
     * rest of the summary information.
     *
     * @return string
     */
    public function getSummaryOfIssues()
    {
        $totalTargets = count($this->targets);
        $targetCollection = collect($this->targets);
        // Count the number of results that are not okay (using the critical state )
        $totalNotOkay = $targetCollection->countBy(function ($target) {
            return $target->result->getStatus() === Result::OK ? null : Result::CRITICAL;
        })->get(Result::CRITICAL);

        $more = collect($targetCollection)->map(function ($target) use($totalTargets, $totalNotOkay) {
            if (empty($target->result->errorMessage)) {
                return null;
            }
            $targetInfo = '';
            // Append target name and status if there is more than one target
            if ($totalTargets > 1) {
                $targetInfo = "== {$target->name}";
            }
            // Append getStatus, if there is more than one level of severity above OK (e.g. Critical + Warning/Unknown)
            if (!empty($targetInfo) && $totalNotOkay > 1) {
                $targetInfo .= " ({$target->result->getStatus()})";
            }
            if (!empty($targetInfo)) {
                $targetInfo .= " ==\n";
            }
            $errorMessage = "{$targetInfo}{$target->result->errorMessage}";
            return $errorMessage;
        })->filter()->join("\n")
        ;

        if (empty($more)) {
            $checkerClassName = (new \ReflectionClass(get_class($this->checker)))->getName();
            $more = "{$checkerClassName} was used to determine the health";
        }

        return $more;
    }

    /**
     * Returns the summary format of the resource
     *
     * @return mixed
     */
    public function getSummary()
    {
        $format = 'M j H:m:s';
        $now = date($format, time());

        // Sets the message ($msg) for each affected resource, will use warning
        // and error messages defined in the config.yaml where relevant.
        $resourceStatus = $this->getStatus();
        switch ($resourceStatus) {
            case Result::WARNING:
                if (!empty($this->warningMessage)) {
                    $msg = $this->warningMessage;
                } else {
                    $msg = $this->name;
                }
                break;
            case Result::CRITICAL:
                if (!empty($this->errorMessage)) {
                    $msg = $this->errorMessage;
                } else {
                    $msg = $this->name;
                }
                break;
            case Result::UNKNOWN:
            case Result::OK:
                $msg = $this->name;
                break;
        }

        // Additional details to include in the response, such as errors/warnings
        $more = '';
        if (empty($more) && in_array($resourceStatus, [Result::WARNING, Result::CRITICAL])) {
            $more = $this->getSummaryOfIssues();
        }

        // Ignore $more if it matches the $msg (to reduce redundant information).
        if ($msg === $more) {
            $more = '';
        }

        // Ensure $more information is prefixed using a new line to see the information more easily.
        if (!empty($more)) {
            $more = "\n".$more;
        }

        // Put together the summary for the resource using it's components.
        $response = \strtoupper($this->getStatus()).": {$msg} (Checked {$now}){$more}";

        return $response;
    }

    protected function keysToCamel($array)
    {
        return collect($array)->mapWithKeys(function ($item, $key) {
            return [Str::camel($key) => $item];
        });
    }

    /**
     * Notify about health problems.
     */
    protected function notify()
    {
        if ($this->canNotify()) {
            $this->sendNotifications();
        }
    }

    /**
     * Send notifications.
     *
     * @return static
     */
    protected function sendNotifications()
    {
        return collect(config('health.notifications.channels'))->each(function (
            $channel
        ) {
            try {
                event(new RaiseHealthIssue($this, $channel));
            } catch (\Exception $exception) {
                report($exception);
            }
        });
    }

    /**
     * Can we notify about errors on this resource?
     *
     * @return bool
     */
    protected function canNotify()
    {
        return
            ! $this->notified &&
            $this->notificationsAreEnabled() &&
            ! $this->isHealthy();
    }

    /**
     * Is notification enabled for this resource?
     *
     * @return bool
     */
    protected function notificationsAreEnabled()
    {
        return
            $this->notify &&
            config('health.notifications.enabled') &&
            config('health.notifications.notify_on.'.$this->currentAction);
    }

    /**
     * Set current action.
     *
     * @param string $currentAction
     * @return resource
     */
    public function setCurrentAction(string $currentAction)
    {
        $this->currentAction = $currentAction;

        return $this;
    }

    /**
     * Resources setter.
     *
     * @param $resources
     * @return resource
     */
    protected function setResources($resources)
    {
        $this->resources = $resources;

        return $this;
    }

    /**
     * Object to json.
     *
     * @return false|string
     */
    public function __toString()
    {
        return json_encode($this->__toArray($this, 6));
    }

    /**
     * Prepare the resource for JSON serialization.
     *
     * @return string
     */
    public function jsonSerialize()
    {
        return json_decode($this->__toString(), true);
    }
}
