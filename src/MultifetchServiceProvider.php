<?php

namespace Marmelab\Multifetch;

use Silex\Application;
use Silex\ServiceProviderInterface;
use Symfony\Component\HttpFoundation\Request;

class MultifetchServiceProvider implements ServiceProviderInterface
{
    public function register(Application $app)
    {
        $app['multifetch.methods'] = ['POST'];
        $app['multifetch.url'] = 'multi';
        $app['multifetch.parallel'] = false;
        $app['multifetch.headers'] = true;

        $app['multifetch.builder'] = function () use ($app) {
            $controllers = $app['controllers_factory'];
            $multifetcher = new Multifetcher();
            $options = [
                'parallel' => (bool) $app['multifetch.parallel'],
                'headers' => (bool) $app['multifetch.headers']
            ];

            if (in_array('POST', $app['multifetch.methods'])) {
                $controllers
                    ->post('/', function (Application $app) use ($multifetcher, $options) {
                        $responses = $multifetcher->fetch($app, $options);

                        return $app->json($responses);
                    })
                    ->before(function (Request $request) {
                        if (0 === strpos($request->headers->get('Content-Type'), 'application/json')) {
                            $data = json_decode($request->getContent(), true);
                            $request->request->replace(is_array($data) ? $data : []);
                        }
                    })
                ;
            }

            $app['controllers']->mount($app['multifetch.url'], $controllers);
        };
    }

    public function boot(Application $app)
    {
        $app['multifetch.builder'];
    }
}
