<?php

namespace PragmaRX\Health\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\File;
use PragmaRX\Health\Service;
use Illuminate\Http\Request;
use PragmaRX\Health\Support\Result;

class Health extends Controller
{
    /**
     * @var Service
     */
    private $healthService;

    /**
     * Health constructor.
     *
     * @param Service $healthService
     */
    public function __construct(Service $healthService)
    {
        $this->healthService = $healthService;
    }

    /**
     * Check all resources.
     *
     * @return array
     * @throws \Exception
     */
    public function check()
    {
        $this->healthService->setAction('check');

        return response($this->healthService->health());
    }

    /**
     * Check and get one resource.
     *
     * @param $slug
     * @return mixed
     * @throws \Exception
     */
    public function getResource($slug, Request $request)
    {
        $this->healthService->setAction('resource');

        $resource = $this->healthService->resource($slug);

        // Get any expected response format (fallsback to JSON)
        $format = $request->get('format');

        // Summary format - Prints status: resource, and any extra piece of information we have.
        if ($format === 'summary') {
            return response($resource->getSummary())
                ->header('Content-Type', 'text/plain');

        } else {
            return $resource;
        }
    }

    /**
     * Get all resources.
     *
     * @return mixed
     * @throws \Exception
     */
    public function allResources()
    {
        return $this->healthService->getResources();
    }

    /**
     * @return mixed
     * @throws \Exception
     */
    public function string(Request $request)
    {
        $filters = $request->get('filters');
        $format = $request->get('format');
        $sortBy = $request->get('sort');

        $this->healthService->setAction('string');

        return response(
            $this->healthService->string($filters, $format, $sortBy)
        )->header('Content-Type', 'text/plain');
    }

    /**
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     * @throws \Exception
     */
    public function panel()
    {
        $this->healthService->setAction('panel');

        return response((string) view(config('health.views.panel'))->with('laravel', ['health' => config('health')]));
    }

    public function assetAppJs()
    {
        $file = File::get(config('health.assets.js'));

        $response = response()->make($file);

        $response->header('Content-Type', 'text/javascript');

        return $response;
    }

    public function assetAppCss()
    {
        $file = File::get(config('health.assets.css'));

        $response = response()->make($file);

        $response->header('Content-Type', 'text/css');

        return $response;
    }

    public function config()
    {
        return config('health');
    }
}
