<?php

declare(strict_types=1);

use Nolviko\Core\Extensions\ExtensionContext;
use Nolviko\Core\Extensions\ExtensionProvider;
use Nolviko\Core\Extensions\HookDecision;
use Nolviko\Core\Extensions\PostingInput;
use Nolviko\Core\Http\RouteHandler;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Routing\RouteDefinition;
use Nolviko\Core\View\SafeHtml;
use Nolviko\Modules\Permissions\Domain\PermissionDefinition;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

return new class implements ExtensionProvider {
    public function register(ExtensionContext $context): void
    {
        $context->permission(PermissionDefinition::bool('acme.hello.view'));
        $context->beforePosting(static fn(PostingInput $input): HookDecision => HookDecision::allow());
        $context->slot(
            'layout.header.after',
            static fn(array $context): SafeHtml => new SafeHtml(
                '<span class="acme-hello" aria-hidden="true">★</span>',
            ),
        );
        $context->route(
            new RouteDefinition('acme.hello.index', '/extensions/acme/hello/index', ['GET']),
            'acme.hello.view',
            static fn(MessageTranslator $translator): RouteHandler => new class ($translator) implements RouteHandler {
                public function __construct(private readonly MessageTranslator $translator)
                {
                }

                public function handle(Request $request, array $parameters): Response
                {
                    return new Response($this->translator->translate('acme.hello.greeting'), 200, [
                        'Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store',
                    ]);
                }
            },
            [MessageTranslator::class],
        );
    }
};
