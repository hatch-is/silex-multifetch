<?php

namespace Marmelab\Multifetch;

use KzykHys\Parallel\Parallel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class Multifetcher
{
    public function fetch($app, array $options = [])
    {
        $parameters = $app['request']->request->all();
        $options = array_replace([
            'parallel' => false,
            'headers' => true,
        ], $options);

        foreach ($options as $name => $value) {
            if (isset($parameters['_'.$name])) {
                $options[$name] = $parameters['_'.$name];
                unset($parameters['_'.$name]);
            }
        }

        if ($options['parallel'] && !class_exists('\KzykHys\Parallel\Parallel')) {
            throw new \RuntimeException(
                '"tiagobutzke/phparallel" library is required to execute requests in parallel.
                To install it, run `composer require tiagobutzke/phparallel "~0.1"`'
            );
        }

        $requests = [];
        foreach ($parameters as $requestParams) {

            $this->checkRequestParams($requestParams);
            $request = $this->formatRequest($app, $requestParams);
            $relativeUrl = $requestParams['relative_url'];

            $requests[] = function ()
            use ($relativeUrl, $request, $app) {
                try {
                    /** @var Response $response */
                    $response = $this->makeSubRequest($app, $request);

                    $code = $response->getStatusCode();
                    $headers = $this->formatHeaders($response->headers->all());
                    $body = $response->getContent();

                } catch (HttpException $e) {
                    $reflectionClass = new \ReflectionClass($e);
                    $type = $reflectionClass->getShortName();

                    $code = $e->getStatusCode();
                    $headers = $this->formatHeaders($e->getHeaders());
                    $body = json_encode(
                        [
                            'error' => $e->getMessage(),
                            'type'  => $type
                        ]
                    );

                } catch (\Exception $e) {

                    $code = 500;
                    $headers = [];
                    $body = json_encode(
                        [
                            'error' => $e->getMessage(),
                            'type'  => 'InternalServerError'
                        ]
                    );
                }

                return [
                    'code' => $code,
                    'headers' => $headers,
                    'body' => $body
                ];
            };
        }

        $responses = [];
        if ($options['parallel']) {
            $parallel = new Parallel();

            $responses = $parallel->values($requests);

        } else {
            foreach ($requests as $resource => $callback) {
                $responses[$resource] = $callback();
            }
        }

        if (!$options['headers']) {
            array_walk($responses, function (&$value) {
                unset($value['headers']);
            });
        }

        return $responses;
    }

    private function formatHeaders(array $headers)
    {
        return array_map(function ($name, $value) {
            return array('name' => $name, 'value' => current($value));
        }, array_keys($headers), $headers);
    }

    /**
     * @param array $requestParams
     *
     * @return Request
     */
    private function formatRequest($app, array $requestParams)
    {
        $method = strtoupper($requestParams['method']);
        $relativeUrl = $requestParams['relative_url'];
        $server = $app['request']->server->all();
        $headers = isset($requestParams['headers'])?$requestParams['headers']:[];

        $body = isset($requestParams['body']) ? $requestParams['body'] : [];
        $request = Request::create(
            $relativeUrl,
            $method,
            $body,
            [],
            [],
            $server
        );

        $request->headers->add($headers);

        return $request;
    }

    private function checkRequestParams(array $requestParams)
    {
        if(!isset($requestParams['relative_url'])) {
            throw new HttpException(400, 'relative_url param should exist');
        }
        if(!isset($requestParams['method'])) {
            throw new HttpException(400, 'method param should exist');
        }
    }

    private function makeSubRequest($app, $request)
    {
        $response = $app->handle(
            $request,
            HttpKernelInterface::SUB_REQUEST,
            true
        );

        return $response;
    }
}
