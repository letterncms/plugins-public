<?php

declare(strict_types=1);

namespace Cms\Brevo\Http;

use Illuminate\Http\Request;
use Cms\Brevo\Signup;
use Cms\Cms;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** What the signup block posts to, and where the link in the confirmation email leads. Works with and without JavaScript. */
final class SignupController
{
    public function __construct(private readonly Cms $cms, private readonly Signup $signup) {}

    public function subscribe(Request $request): Response
    {
        $locale = $this->signup->speak((string) $request->input('_cms_locale', ''));
        $id = preg_match('/^brevo-signup-[A-Za-z0-9_-]{1,40}$/', (string) $request->input('_cms_id', '')) ? (string) $request->input('_cms_id') : 'brevo-signup';
        $back = strtok((string) $request->headers->get('referer', '/'), '#');

        if (! $this->originAllowed($request)) {
            return $this->respond($request, 403, ['message' => __('Signups from this website are not accepted.')]);
        }

        // A filled trap gets the answer a signup gets, so bots learn nothing.
        if ((string) $request->input('_cms_website', '') !== '') {
            return $this->respond($request, 200, ['ok' => true], $back.'#'.$id.'-ok');
        }

        $email = mb_strtolower(trim((string) $request->input('email', '')));
        $list = (int) $request->input('list', 0);

        if (strlen($email) > 254 || ! filter_var($email, FILTER_VALIDATE_EMAIL) || $list < 1 || ! $this->signup->sealed($list, (string) $request->input('seal', ''))) {
            return $this->respond($request, 422, ['message' => __('That didn’t work. Check the address and try again.')], $back.'#'.$id.'-error');
        }

        try {
            // The same answer whether Brevo knows the address or not: nothing here says who is on a list.
            $this->signup->request($email, $list, $locale);
        } catch (Throwable $e) {
            report($e);

            return $this->respond($request, 503, ['message' => __('Signing up isn’t possible right now. Please try again later.')], $back.'#'.$id.'-error');
        }

        return $this->respond($request, 200, ['ok' => true], $back.'#'.$id.'-ok');
    }

    /** Where the link in the email leads: a page that asks, and subscribes nobody. Mail scanners and link previews stop here. */
    public function ask(Request $request): Response
    {
        $token = (string) $request->query('token', '');
        $claim = $this->signup->read($token);
        $this->signup->speak($claim['locale'] ?? '');

        if ($claim === null) {
            return $this->gone();
        }

        return $this->page(200, __('Confirm your subscription'), __('Add :email to the list?', ['email' => $claim['email']]), $token);
    }

    /** The button of that page: only this puts the address on its list. */
    public function confirm(Request $request): Response
    {
        $token = (string) $request->input('token', '');
        $claim = $this->signup->read($token);
        $this->signup->speak($claim['locale'] ?? '');

        if ($claim === null) {
            return $this->gone();
        }

        try {
            $this->signup->confirm($token);
        } catch (Throwable $e) {
            report($e);

            return $this->page(503, __('That didn’t work'), __('Signing up isn’t possible right now. Please try again later.'));
        }

        $to = trim((string) $this->cms->setting('brevo', 'confirmed_redirect', ''));

        if ($to !== '' && (preg_match('#^/(?!/)#', $to) || parse_url($to, PHP_URL_HOST) === parse_url($this->cms->url(), PHP_URL_HOST))) {
            return redirect()->away($to, 303);
        }

        return $this->page(200, __('You’re subscribed'), __('Thanks for confirming your address.'));
    }

    private function gone(): Response
    {
        return $this->page(410, __('This link no longer works'), __('It was changed or is more than two days old. Sign up again to get a new one.'));
    }

    /** CORS preflight for sites on another domain. */
    public function options(Request $request): Response
    {
        return $this->cors($request, response('', 204));
    }

    /**
     * A page of its own for the confirmation link: it works on a site that has no pages yet, and says one thing.
     * It wears the site's design (its stylesheet, tokens and button) when the Site module is on. With a
     * token it asks, and its button posts that token back.
     */
    private function page(int $status, string $title, string $text, ?string $token = null): Response
    {
        $home = e($this->cms->url('/'));
        $site = e((string) config('app.name'));
        $lang = e(app()->getLocale());
        $design = '';

        try {
            if ($this->cms->isActive('letterncms/site')) {
                $design = '<link rel="stylesheet" href="'.e($this->cms->app()->make(\Cms\Modules\Site\Design\Design::class)->stylesheet()).'">';
            }
        } catch (Throwable) {
        }

        $form = $token === null ? '' : '<form method="post" action="'.e(($this->signup->base() ?: '').'/api/brevo/confirm').'"><input type="hidden" name="token" value="'.e($token).'">'
            .'<button type="submit" class="cms-button" data-variant="primary"><span>'.e(__('Confirm Subscription')).'</span></button></form>';

        return response('<!doctype html><html lang="'.$lang.'"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><meta name="referrer" content="no-referrer"><title>'.e($title).' · '.$site.'</title>'
            // In a layer declared first, so the site's design wins wherever it speaks; these are what a site without one gets.
            .'<style>@layer fallback{:root{color-scheme:light dark}body{font:16px/1.5 system-ui,sans-serif;background:Canvas;color:CanvasText}}body{margin:0;min-height:100vh;display:grid;place-items:center}</style>'.$design
            .'<style>.brevo-page{max-width:28rem;padding:2rem}.brevo-page h1{font-size:1.5rem;margin:0 0 .5rem}.brevo-page p{margin:0 0 1.25rem;overflow-wrap:anywhere}.brevo-page form{margin:0 0 1.25rem}.brevo-page button{min-height:44px}.brevo-page a{color:inherit}</style></head>'
            .'<body><main class="brevo-page"><h1>'.e($title).'</h1><p>'.e($text).'</p>'.$form.'<p><a href="'.$home.'">'.$site.'</a></p></main></body></html>', $status, [
                'Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer',
                // No script at all; styles and fonts from here only, and the button posts only to where the CMS answers.
                'Content-Security-Policy' => "default-src 'none'; style-src 'self' 'unsafe-inline'; font-src 'self'; img-src 'self' data:; form-action 'self'".($this->signup->base() !== '' ? ' '.$this->signup->base() : '')."; frame-ancestors 'none'",
            ]);
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
        return array_values(array_unique(array_filter([rtrim($request->getSchemeAndHttpHost(), '/'), rtrim($this->cms->url(), '/')])));
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
