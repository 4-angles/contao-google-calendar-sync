<?php
# Legacy codes, works till Contao 6 
$GLOBALS['TL_CTE']['texts']['code'] = \Contao\ContentCode::class;
$GLOBALS['TL_CTE']['texts']['headline'] = \Contao\ContentHeadline::class;
$GLOBALS['TL_CTE']['texts']['html'] = \Contao\ContentHtml::class;
$GLOBALS['TL_CTE']['texts']['list'] = \Contao\ContentList::class;
$GLOBALS['TL_CTE']['texts']['table'] = \Contao\ContentTable::class;
$GLOBALS['TL_CTE']['links']['hyperlink'] = \Contao\ContentHyperlink::class;
$GLOBALS['TL_CTE']['links']['toplink'] = \Contao\ContentToplink::class;
$GLOBALS['TL_CTE']['media']['image'] = \Contao\ContentImage::class;
$GLOBALS['TL_CTE']['media']['gallery'] = \Contao\ContentGallery::class;
$GLOBALS['TL_CTE']['media']['player'] = \Contao\ContentPlayer::class;
$GLOBALS['TL_CTE']['media']['youtube'] = \Contao\ContentYouTube::class;
$GLOBALS['TL_CTE']['media']['vimeo'] = \Contao\ContentVimeo::class;
$GLOBALS['TL_CTE']['files']['downloads'] = \Contao\ContentDownloads::class;
$GLOBALS['TL_CTE']['files']['download'] = \Contao\ContentDownload::class;
$GLOBALS['TL_CTE']['includes']['teaser'] = \Contao\ContentTeaser::class;
$GLOBALS['TL_CTE']['texts']['text'] = \Contao\ContentText::class;

// Google Calendar Settings Backend Module
$GLOBALS['BE_MOD']['system']['google_calendar_settings'] = [
    'tables' => ['tl_google_calendar_settings'],
    'icon' => 'bundles/fouranglescontaogooglecalendar/icons/sync-calendar.svg'
];

