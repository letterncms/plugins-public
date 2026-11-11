<?php

declare(strict_types=1);

namespace Cms\Forms\Captcha;

/**
 * A challenge a visitor passes before a form is accepted. The page carries the provider's
 * widget, which puts a token in the form; the server asks the provider whether it holds.
 */
interface Captcha
{
    /** The provider's script, loaded once on pages that show a form. */
    public function script(): string;

    /** The widget's markup inside the form. Its size is reserved, so nothing moves when it loads. */
    public function widget(string $locale): string;

    /** The name of the form field the widget puts its token in. */
    public function field(): string;

    /** The JavaScript global that resets the widget once its token is spent (`window.<name>.reset()`). */
    public function global(): string;

    /** The longest token the provider hands out, with room to spare: anything longer isn't one, and isn't sent on. */
    public function maxToken(): int;

    /** Whether the provider vouches for the token. Null when it can't be asked or answers nonsense: nothing gets through then either, but the visitor is told it isn't them. */
    public function verify(string $token): ?bool;
}
