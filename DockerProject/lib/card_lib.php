<?php
// カード（アイテム）関連の共通処理

const CARD_IMAGE_DIR = '/images/cards/';        // htdocs から見た画像フォルダ
const CARD_NO_IMAGE  = 'no_image.svg';          // 画像が登録されていないときの代わりの画像

/**
 * cards.image（ファイル名）から、<img src="..."> に入れるURLを作る
 */
function card_image_url(?string $image): string
{
    // DBの値をそのままパスに使うので、フォルダをまたぐ指定（../ など）は受け付けない
    if ($image === null || $image === '' || basename($image) !== $image) {
        $image = CARD_NO_IMAGE;
    }
    return CARD_IMAGE_DIR . rawurlencode($image);
}
