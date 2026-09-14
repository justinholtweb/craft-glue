<?php

declare(strict_types=1);

namespace justinholtweb\glue\web\assets\merge;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * The merge screen's own behaviour: live previews, bulk choice buttons, the reload controls.
 *
 * Plain ES5-compatible JavaScript with no build step, like the rest of the family. The screen is
 * a form with radio buttons; the JavaScript makes it pleasant, and everything it does has a
 * server-side equivalent, so a merge still works with it switched off.
 */
class MergeAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';
        $this->depends = [CpAsset::class];
        $this->js = ['glue-merge.js'];
        $this->css = ['glue-merge.css'];

        parent::init();
    }
}
