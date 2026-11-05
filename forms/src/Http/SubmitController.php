<?php

declare(strict_types=1);

namespace Cms\Forms\Http;

use Illuminate\Http\Request;
use Cms\Admin\Translations;
use Cms\Forms\FormRepository;
use Cms\Forms\SubmissionHandler;
use Cms\Cms;
use Symfony\Component\HttpFoundation\Response;

/** Public endpoint static pages post to. Works with and without JavaScript. */
final class SubmitController
{
    public function __construct(private readonly Cms $cms) {}

    public function submit(Request $request, string $handle, FormRepository $forms, SubmissionHandler $handler, Translations $translations): Response
    {
        // Messages in the language of the page the form is on (when the site or the CMS speaks it), else the admin's default.
        $asked = (string) $request->input('_cms_locale', '');
        $known = in_array($asked, $this->cms->locales(), true) || isset($translations->languages()[$asked]);
        $translations->apply($known ? $asked : $translations->locale());

        $form = $forms->find($handle, $known ? $asked : null);

        if (! $form || ! $form['settings']['enabled']) {
            return $this->respond($request, 404, ['message' => __('This form does not exist.')]);
        }

        if (! $this->originAllowed($request)) {
            return $this->respond($request, 403, ['message' => __('Submissions from this website are not accepted.')]);
        }

        $result = $handler->handle($form, $request);
        $back = (string) $request->headers->get('referer', '/');

        if ($result['status'] === 'invalid') {
            return $this->respond($request, 422, ['message' => __('Please check the highlighted fields and try again.'), 'errors' => $result['errors']], strtok($back, '#').'#cms-form-'.$handle.'-error');
        }

        // Spam gets the same answer as success so bots learn nothing.
        $redirect = $form['settings']['redirect'] ?: null;

        return $this->respond($request, 200, ['ok' => true, 'message' => (string) $form['settings']['success'], 'redirect' => $redirect], $redirect ?: strtok($back, '#').'#cms-form-'.$handle.'-ok');
    }

    /** CORS preflight for sites on another domain. */
    public function options(Request $request): Response
    {
        return $this->cors($request, response('', 204));
    }

    private function respond(Request $request, int $status, array $body, ?string $redirect = null): Response
    {
        if (! $request->expectsJson() && $redirect !== null && $this->safeRedirect($redirect, $request)) {
            return redirect()->away($redirect, 303);
        }

        return $this->cors($request, response()->json($body, $status));
    }

    private function cors(Request $request, Response $response): Response
    {
        $origin = (string) $request->headers->get('Origin', '');

        if ($origin !== '' && in_array(rtrim($origin, '/'), $this->allowedOrigins($request), true)) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Access-Control-Allow-Methods', 'POST, OPTIONS');
            $response->headers->set('Access-Control-Allow-Headers', 'Accept, Content-Type');
            $response->headers->set('Vary', 'Origin');
        }

        return $response;
    }

    private function originAllowed(Request $request): bool
    {
        $origin = $request->headers->get('Origin');

        return $origin === null || $origin === 'null' ? true : in_array(rtrim($origin, '/'), $this->allowedOrigins($request), true);
    }

    /** @return list<string> */
    private function allowedOrigins(Request $request): array
    {
        $origins = [rtrim($request->getSchemeAndHttpHost(), '/'), $this->cms->url()];

        foreach ((array) $this->cms->settings('forms', 'origins', []) as $origin) {
            $origins[] = rtrim((string) $origin, '/');
        }

        return array_values(array_unique(array_filter($origins)));
    }

    private function safeRedirect(string $url, Request $request): bool
    {
        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return true;
        }

        $host = parse_url($url, PHP_URL_HOST);

        foreach ($this->allowedOrigins($request) as $origin) {
            if ($host && $host === parse_url($origin, PHP_URL_HOST)) {
                return true;
            }
        }

        return false;
    }
}
