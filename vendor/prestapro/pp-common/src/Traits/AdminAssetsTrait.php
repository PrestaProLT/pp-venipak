<?php

declare(strict_types=1);

namespace PrestaPro\Common\Traits;

/**
 * Provides the branded header/footer HTML for admin config pages.
 * Used by hookDisplayBackOfficeHeader or rendered directly in Twig.
 */
trait AdminAssetsTrait
{
    /**
     * Get the path to pp-common assets directory.
     * Must be called from a Module context.
     */
    protected function getPPCommonAssetsPath(): string
    {
        return $this->getLocalPath() . 'vendor/prestapro/pp-common/views/';
    }

    /**
     * Get module metadata for the branded header.
     *
     * @return array{name: string, version: string, display_name: string, docs_url: string, support_url: string, github_url: string}
     */
    protected function getHeaderData(): array
    {
        return [
            'name' => $this->name,
            'version' => $this->version,
            'display_name' => $this->displayName,
            'docs_url' => ($this->author_uri ?? 'https://prestapro.lt') . '/modules/' . $this->name,
            'support_url' => 'mailto:modules@prestapro.lt',
            'github_url' => 'https://github.com/PrestaProLT/' . str_replace('pp', 'pp-', $this->name),
        ];
    }
}
