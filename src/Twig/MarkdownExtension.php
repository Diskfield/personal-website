<?php

namespace App\Twig;

use League\CommonMark\CommonMarkConverter;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class MarkdownExtension extends AbstractExtension
{
    public function __construct(
        private CommonMarkConverter $converter
    ) {}

    public function getFilters(): array
    {
        return [
            new TwigFilter('markdown', [$this, 'toHtml'], ['is_safe' => ['html']]),
        ];
    }

    public function toHtml(string $markdown): string
    {
        return $this->converter->convert($markdown)->getContent();
    }
}
