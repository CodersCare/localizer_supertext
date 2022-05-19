<?php

namespace Localizationteam\LocalizerSupertext\Hooks;

use Localizationteam\Localizer\Constants;
use Localizationteam\Localizer\Language;
use Localizationteam\LocalizerSupertext\Api\ApiCalls;
use TYPO3\CMS\Core\Messaging\FlashMessage;

/**
 * DataHandler $COMMENT$
 *
 * @author      Peter Russ<peter.russ@4many.net>
 * @package     TYPO3
 * @date        20150803-2107
 * @subpackage  localizer
 *
 */
class DataHandler
{
    use Language;

    /**
     * hook to post process TCA - Field Array
     * and to alter the configuration
     *
     * @param string $status
     * @param string $table
     * @param int $id
     * @param array $fieldArray
     * @param \TYPO3\CMS\Core\DataHandling\DataHandler $tceMain
     * @throws \TYPO3\CMS\Core\Package\Exception
     */
    public function processDatamap_postProcessFieldArray(
        string $status,
        string $table,
        int $id,
        array &$fieldArray,
        \TYPO3\CMS\Core\DataHandling\DataHandler &$tceMain
    ) {
        if ($table === Constants::TABLE_LOCALIZER_SETTINGS) {
            if ($this->isSaveAction()) {
                $currentRecord = $tceMain->recordInfo($table, $id, '*');
                if ($currentRecord === null) {
                    $currentRecord = [];
                }
                $checkArray = array_merge($currentRecord, $fieldArray);
                if ($checkArray['type'] === 'localizer_supertext') {
                    $localizerApi = new ApiCalls(
                        $checkArray['type'],
                        $checkArray['url'],
                        $checkArray['workflow'],
                        $checkArray['projectkey'],
                        $checkArray['username'],
                        $checkArray['password']
                    );
                    try {
                        $valid = $localizerApi->areSettingsValid();
                        if ($valid === false) {
                            //should never arrive here as exception should occur!
                            $fieldArray['hidden'] = 1;
                        } else {
                            $fieldArray['hidden'] = 0;
                            $fieldArray['project_settings'] = 'Localizer settings [' . $checkArray['title'] . '] successfully validated and saved';
                            $fieldArray['last_error'] = '';
                            new FlashMessage('Localizer settings [' . $checkArray['title'] . '] successfully validated and saved',
                                'Success', 0);
                        }
                    } catch (\Exception $e) {
                        $fieldArray['hidden'] = 1;
                        $fieldArray['project_settings'] = 'Localizer settings [' . $checkArray['title'] . '] set to hidden';
                        $fieldArray['last_error'] = $e->getCode() . ' # ' . $e->getMessage();
                        new FlashMessage($e->getMessage());
                        new FlashMessage('Localizer settings [' . $checkArray['title'] . '] set to hidden', 'Error', 1);
                    }
                    $localizerApi->disconnect();
                }
            }
        }
    }

    /**
     * @return bool
     */
    protected function isSaveAction(): bool
    {
        return
            isset($_REQUEST['doSave']) && (bool)$_REQUEST['doSave'];
    }

}
