<?php
declare(strict_types=1);

namespace Belsignum\BootstrapBlocks\Form\FormDataProvider;

use TYPO3\CMS\Backend\Form\FormDataProviderInterface;

/**
 * Injects itemGroups (optgroup labels) on field level before TcaSelectItems runs.
 * Labels are read from PageTSConfig under bootstrapBlocks.<feature>.<group>.label
 *
 * Supported features and their tt_content field names:
 *  - buttonVariants   -> belsignum_bb_cta_variant
 *  - icons            -> belsignum_bb_icon_name
 *  - spriteSymbols    -> belsignum_bb_teaser_sprite
 */
final class BootstrapBlocksItemGroupsProvider implements FormDataProviderInterface
{
    /**
     * Map tt_content field => TS feature key
     */
    private const FIELD_TO_FEATURE = [
        'belsignum_bb_cta_variant'   => 'buttonVariants',
        'belsignum_bb_icon'     => 'icons',
        'belsignum_bb_teaser_sprite' => 'spriteSymbols',
    ];

    public function addData(array $data): array
    {
        if (($data['tableName'] ?? '') !== 'tt_content') {
            return $data;
        }
        // Guard: we only work when columns/TCA are present
        if (empty($data['processedTca']['columns']) || !is_array($data['processedTca']['columns'])) {
            return $data;
        }

        // PageTS is already resolved by FormEngine here
        $pageTs = $data['pageTsConfig'] ?? [];
        $bbTs   = $pageTs['bootstrapBlocks.'] ?? [];

        foreach (self::FIELD_TO_FEATURE as $fieldName => $featureKey) {
            // Field must exist in processed TCA
            if (!isset($data['processedTca']['columns'][$fieldName])) {
                continue;
            }

            // Build itemGroups: start with a default "core" label per feature
            $itemGroups = $this->getDefaultCoreGroup($featureKey);

            // Merge TS groups: bootstrapBlocks.<feature>.<group>.label
            $featureTs = $bbTs[$featureKey . '.'] ?? [];
            foreach ($featureTs as $groupKeyWithDot => $groupConf) {
                if (!is_array($groupConf)) {
                    continue;
                }
                $groupKey = rtrim((string)$groupKeyWithDot, '.');
                if ($groupKey === '') {
                    continue;
                }
                $label = (string)($groupConf['label'] ?? $groupKey);
                $label = trim($label) !== '' ? $label : $groupKey;

                // IMPORTANT: itemGroups must be on FIELD LEVEL in v12, not in config
                $itemGroups[$groupKey] = $label;
            }

           # $data['processedTca']['columns'][$fieldName]['itemGroups'] = $itemGroups;
            $data['processedTca']['columns'][$fieldName]['config']['itemGroups'] = $itemGroups;
        }

        return $data;
    }

    /**
     * Default core group label per feature.
     */
    private function getDefaultCoreGroup(string $featureKey): array
    {
        switch ($featureKey) {
            case 'buttonVariants':
                return ['core' => 'Core (Bootstrap)'];
            case 'icons':
            case 'spriteSymbols':
            default:
                return ['core' => 'Default'];
        }
    }
}
