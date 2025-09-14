<?php
declare(strict_types=1);

namespace Belsignum\BootstrapBlocks\Tca;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider;
use TYPO3\CMS\Core\Imaging\IconRegistry;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class ItemsProvider
{
    /**
     * Resolve PageTS for the current record (falls back to the first site root).
     *
     * @param array $config itemsProcFunc config
     * @return array Merged PageTS array
     */
    private function resolvePageTs(array $config): array
    {
        $pid = 0;

        if (!empty($config['row']['pid'])) {
            $pid = (int)$config['row']['pid'];
        }

        if ($pid <= 0) {
            try {
                $sites = GeneralUtility::makeInstance(SiteFinder::class)->getAllSites();
                if (!empty($sites)) {
                    $pid = (int)reset($sites)->getRootPageId();
                }
            } catch (\Throwable) {
                // ignore
            }
        }

        return BackendUtility::getPagesTSconfig($pid);
    }

    /**
     * Normalize a TS array (with trailing dots) into [key => value] without dots.
     *
     * @param array|null $ts
     * @return array<string,mixed>
     */
    private function normalizeTsArray(?array $ts): array
    {
        if (!is_array($ts)) {
            return [];
        }
        $out = [];
        foreach ($ts as $k => $v) {
            $out[rtrim((string)$k, '.')] = $v;
        }
        return $out;
    }

    /**
     * Resolve a public or EXT: relative path to an absolute filesystem path.
     * - If it starts with EXT:, use getFileAbsFileName()
     * - Otherwise treat as relative to public/ (Environment::getPublicPath())
     */
    private function resolveAbsolutePath(string $path): ?string
    {
        $path = trim($path);
        if ($path === '') {
            return null;
        }

        if (str_starts_with($path, 'EXT:')) {
            $abs = GeneralUtility::getFileAbsFileName($path);
            return ($abs && (is_dir($abs) || is_file($abs))) ? rtrim($abs, '/') : null;
        }

        // strip leading slash to allow both "/assets/..." and "assets/..."
        $path = ltrim($path, '/');
        $abs = rtrim(Environment::getPublicPath(), '/') . '/' . $path;
        return (is_dir($abs) || is_file($abs)) ? rtrim($abs, '/') : null;
    }

    /**
     * Build items from a directory with single SVG files.
     * Each SVG is used as its own icon. Uses file mtime in the identifier to auto-bust on change.
     *
     * @param string $absDir Absolute directory path
     * @param string $groupKey Group identifier to assign
     * @param string $iconPrefix Unique prefix for icon identifiers
     * @return array<int, array{label:string,value:string,group:string,icon:string}>
     */
    private function buildItemsFromSvgDir(string $absDir, string $groupKey, string $iconPrefix): array
    {
        $result = [];
        if (!is_dir($absDir)) {
            return $result;
        }

        $files = glob($absDir . '/*.svg') ?: [];
        if ($files === []) {
            return $result;
        }

        /** @var IconRegistry $iconRegistry */
        $iconRegistry = GeneralUtility::makeInstance(IconRegistry::class);

        foreach ($files as $file) {
            $basename = basename($file, '.svg');
            $value = $basename;
            $label = $this->titleFromSlug($basename);
            $mtime = (string)@filemtime($file);
            $identifier = $iconPrefix . sha1($file . '|' . $mtime);

            if (!$iconRegistry->isRegistered($identifier)) {
                $iconRegistry->registerIcon(
                    $identifier,
                    SvgIconProvider::class,
                    ['source' => $file]
                );
            }

            $result[] = [
                'label' => $label,
                'value' => $value,
                'group' => $groupKey,
                'icon'  => $identifier,
            ];
        }

        usort($result, static fn(array $a, array $b) => strcmp($a['label'], $b['label']));
        return $result;
    }

    /**
     * Read a single SVG sprite file and create items for each <symbol id="...">.
     * Generates standalone SVGs into public/typo3temp/assets/bb_sprites for BE preview.
     * Uses file mtime in the hash to invalidate and regenerate on changes.
     *
     * - Item labels are de-prefixed if they start with "<groupKey>-" or "<groupKey>_".
     *   Optgroup label still uses the TSconfig label provided for the group.
     *
     * @param string $spriteFile Absolute file path to sprite.svg
     * @param string $groupKey   Item group key
     * @param string $iconPrefix Prefix for icon identifier hashing
     * @return array<int, array{label:string,value:string,group:string,icon:string}>
     */
    private function buildItemsFromSpriteFile(string $spriteFile, string $groupKey, string $iconPrefix): array
    {
        static $requestCache = []; // cache per request by spriteFile path + group

        if (isset($requestCache[$spriteFile][$groupKey])) {
            return $requestCache[$spriteFile][$groupKey];
        }

        $result = [];
        if (!is_file($spriteFile) || !is_readable($spriteFile)) {
            $requestCache[$spriteFile][$groupKey] = $result;
            return $result;
        }

        $svg = @file_get_contents($spriteFile);
        if ($svg === false || $svg === '') {
            $requestCache[$spriteFile][$groupKey] = $result;
            return $result;
        }

        $dom = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($svg);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$loaded) {
            $requestCache[$spriteFile][$groupKey] = $result;
            return $result;
        }

        $symbols = $dom->getElementsByTagName('symbol');
        if ($symbols->length === 0) {
            $requestCache[$spriteFile][$groupKey] = $result;
            return $result;
        }

        // Public directory to expose generated SVGs to the BE as proper URLs
        $publicBase = rtrim(Environment::getPublicPath(), '/') . '/typo3temp/assets/bb_sprites';
        if (!is_dir($publicBase)) {
            GeneralUtility::mkdir_deep($publicBase);
        }

        $spriteMtime = (string)@filemtime($spriteFile);
        $groupKeyLower = strtolower($groupKey);

        /** @var \DOMElement $symbol */
        foreach ($symbols as $symbol) {
            if (!$symbol->hasAttribute('id')) {
                continue;
            }
            $id = trim($symbol->getAttribute('id'));
            if ($id === '') {
                continue;
            }

            // Build label without redundant group prefix
            $idLower = strtolower($id);
            $labelSource = $id;
            if (str_starts_with($idLower, $groupKeyLower . '-')) {
                $labelSource = substr($id, strlen($groupKey) + 1); // drop "groupKey-"
            } elseif (str_starts_with($idLower, $groupKeyLower . '_')) {
                $labelSource = substr($id, strlen($groupKey) + 1); // drop "groupKey_"
            }
            $label = $this->titleFromSlug($labelSource);

            $viewBox = $symbol->getAttribute('viewBox') ?: '0 0 24 24';

            // Extract inner XML of the symbol
            $inner = '';
            foreach ($symbol->childNodes as $child) {
                $inner .= $dom->saveXML($child);
            }

            $standaloneSvg =
                '<?xml version="1.0" encoding="UTF-8"?>'
                . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="' . htmlspecialchars($viewBox, ENT_QUOTES) . '">'
                . $inner
                . '</svg>';

            // Hash includes file path + mtime + symbol id
            $hash = sha1($spriteFile . '|' . $spriteMtime . '|' . $id);
            $iconFilename = 'sprite_' . $hash . '.svg';
            $iconFsPath   = $publicBase . '/' . $iconFilename;
            $iconWebPath  = 'typo3temp/assets/bb_sprites/' . $iconFilename;

            if (!is_file($iconFsPath)) {
                @file_put_contents($iconFsPath, $standaloneSvg);
            }

            $result[] = [
                'label' => $label,       // e.g. "Chart Mixed"
                'value' => $id,          // e.g. "duotone-chart-mixed" (unchanged)
                'group' => $groupKey,    // optgroup still uses TSconfig label
                'icon'  => $iconWebPath, // web-relative URL for BE preview
            ];
        }

        usort($result, static fn(array $a, array $b) => strcmp($a['label'], $b['label']));
        $requestCache[$spriteFile][$groupKey] = $result;
        return $result;
    }

    /**
     * Convert slug-like string (e.g. chevron-double-down) into Title Case label.
     */
    private function titleFromSlug(string $slug): string
    {
        $slug = str_replace(['_', '-'], ' ', $slug);
        $slug = preg_replace('/\s+/', ' ', $slug) ?? $slug;
        $slug = trim($slug);
        return $slug === '' ? '' : ucwords($slug);
    }

    /**
     * Read grouped items from TSconfig according to the single supported structure:
     *
     * bootstrapBlocks {
     *   <featureKey> {
     *     <groupKey> {
     *       label = ...
     *       items { key = Label }       # optional
     *       itemsPath = <dir>           # only for icons
     *       spritesPath = <file>        # only for spriteSymbols
     *     }
     *   }
     * }
     *
     * Uses the group's TSconfig "label" for the optgroup title (all features).
     * For spriteSymbols, item labels may be de-prefixed later (see buildItemsFromSpriteFile()).
     *
     * @param array  $pageTs     Full PageTS array
     * @param string $featureKey One of: buttonVariants | icons | spriteSymbols
     * @param bool   $allowPaths Allow directory/sprite path processing
     * @param string $iconPrefix Icon identifier prefix for registered icons
     * @return array{itemGroups: array<string,string>, items: array<int, array<string,string>>}
     */
    private function readGroupedProjectItems(array $pageTs, string $featureKey, bool $allowPaths, string $iconPrefix): array
    {
        $itemGroups = [];
        $items = [];

        $featureConf = $this->normalizeTsArray($pageTs['bootstrapBlocks.'][$featureKey . '.'] ?? null);
        if ($featureConf === []) {
            return ['itemGroups' => $itemGroups, 'items' => $items];
        }

        foreach ($featureConf as $groupKey => $groupConf) {
            if (!is_array($groupConf)) {
                continue;
            }
            $groupConf = $this->normalizeTsArray($groupConf);

            // Always use the TSconfig "label" for the optgroup title (fallback: groupKey)
            $groupLabel = trim((string)($groupConf['label'] ?? $groupKey));
            $itemGroups[$groupKey] = $groupLabel !== '' ? $groupLabel : $groupKey;

            // Manual items map (TSconfig). Respect provided labels; otherwise generate Title Case.
            $explicitItems = $this->normalizeTsArray($groupConf['items'] ?? null);
            foreach ($explicitItems as $value => $labelText) {
                if ($value === '') { continue; }
                $finalLabel = (string)$labelText;
                if ($finalLabel === '') {
                    // For spriteSymbols we may remove redundant group prefix in value for nicer labels
                    if ($featureKey === 'spriteSymbols') {
                        $valueLower = strtolower($value);
                        $prefixLowerDash = strtolower($groupKey) . '-';
                        $prefixLowerUnd  = strtolower($groupKey) . '_';
                        $labelSource = $value;
                        if (str_starts_with($valueLower, $prefixLowerDash)) {
                            $labelSource = substr($value, strlen($groupKey) + 1);
                        } elseif (str_starts_with($valueLower, $prefixLowerUnd)) {
                            $labelSource = substr($value, strlen($groupKey) + 1);
                        }
                        $finalLabel = $this->titleFromSlug($labelSource);
                    } else {
                        $finalLabel = $this->titleFromSlug($value);
                    }
                }

                $items[] = [
                    'label' => $finalLabel,
                    'value' => $value,
                    'group' => $groupKey,
                ];
            }

            if ($allowPaths) {
                // icons: directory with single SVGs (register previews)
                if (!empty($groupConf['itemsPath']) && $featureKey === 'icons') {
                    $abs = $this->resolveAbsolutePath((string)$groupConf['itemsPath']);
                    if ($abs) {
                        $dirItems = $this->buildItemsFromSvgDir($abs, $groupKey, $iconPrefix);
                        $items = array_merge($items, $dirItems);
                    }
                }

                // spriteSymbols: single sprite file with <symbol id="..."> (generate previews)
                if (!empty($groupConf['spritesPath']) && $featureKey === 'spriteSymbols') {
                    $absSprite = $this->resolveAbsolutePath((string)$groupConf['spritesPath']);
                    if ($absSprite) {
                        $spriteItems = $this->buildItemsFromSpriteFile($absSprite, $groupKey, $iconPrefix);
                        $items = array_merge($items, $spriteItems);
                    }
                }
            }
        }

        // Sort items by label inside groups for consistent UX
        usort($items, static function (array $a, array $b): int {
            if ($a['group'] === $b['group']) {
                return strcmp($a['label'], $b['label']);
            }
            return strcmp($a['group'], $b['group']);
        });

        return ['itemGroups' => $itemGroups, 'items' => $items];
    }

    /**
     * Button variants: Bootstrap defaults in "core" group + project groups (from TSconfig).
     */
    public function buttonVariants(array &$config): void
    {
        $items = [
            ['label' => 'Primary',   'value' => 'primary',   'group' => 'core'],
            ['label' => 'Secondary', 'value' => 'secondary', 'group' => 'core'],
            ['label' => 'Link',      'value' => 'link',      'group' => 'core'],
        ];
        $itemGroups = ['core' => 'Core (Bootstrap)'];

        $pageTs  = $this->resolvePageTs($config);
        $project = $this->readGroupedProjectItems($pageTs, 'buttonVariants', false, '');

        if ($project['itemGroups'] !== []) {
            $itemGroups = array_merge($itemGroups, $project['itemGroups']);
            $items = array_merge($items, $project['items']);
        }

        $config['items'] = $items;
        $config['itemGroups'] = $itemGroups;
    }

    /**
     * Bootstrap icons: default "None" in "core" group + project groups (from TSconfig).
     * Supports itemsPath (folder of single SVGs).
     */
    public function bootstrapIcons(array &$config): void
    {
        $items = [
            ['label' => 'None', 'value' => '', 'group' => 'core'],
        ];
        $itemGroups = ['core' => 'Default'];

        $pageTs  = $this->resolvePageTs($config);
        $project = $this->readGroupedProjectItems($pageTs, 'icons', true, 'bb_icon_');

        if ($project['itemGroups'] !== []) {
            $itemGroups = array_merge($itemGroups, $project['itemGroups']);
            $items = array_merge($items, $project['items']);
        }

        $config['config']['itemGroups'] = $itemGroups;
        $config['items'] = $items;
    }

    /**
     * Sprite symbols: default "Use Media element" in "core" group + project groups (from TSconfig).
     * Supports spritesPath (single SVG sprite file with <symbol> ids).
     */
    public function spriteSymbols(array &$config): void
    {
        $items = [
            ['label' => 'Use Media element', 'value' => '', 'group' => 'core'],
        ];
        $itemGroups = ['core' => 'Default'];

        $pageTs  = $this->resolvePageTs($config);
        $project = $this->readGroupedProjectItems($pageTs, 'spriteSymbols', true, 'bb_sprite_');

        if ($project['itemGroups'] !== []) {
            $itemGroups = array_merge($itemGroups, $project['itemGroups']);
            $items = array_merge($items, $project['items']);
        }

        $config['config']['itemGroups'] = $itemGroups;
        $config['items'] = $items;
    }
}
