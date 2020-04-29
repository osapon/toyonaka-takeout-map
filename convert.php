<?php
mb_internal_encoding('UTF-8');
mb_regex_encoding('UTF-8');

$app_id = $_ENV['APPID'];
$yahoo_geo_url = 'https://map.yahooapis.jp/geocode/V1/geoCoder';
$source_url = 'https://peraichi.com/landing_pages/view/toyonaka2020ouen';
$output_filename = 'toyonaka_takeout_data.json';
$source_checkfile = 'source_update.txt';

$body = file_get_contents($source_url);

function _fw_mb_trim($pString)
{
    return preg_replace('/\A[\p{C}\p{Z}]++|[\p{C}\p{Z}]++\z/u', '', $pString);
}

preg_match('/最終更新日時(.+?)</', $body, $match);
if (file_get_contents($source_checkfile) == $match[1]) {
    exit(1);
}
file_put_contents($source_checkfile, $match[1]);

// xpathで簡単に抽出しようかと思ったけど、プログラム向けの法則性があるHTMLではなかったので、文脈から認識させる
$total_len = strlen($body);
preg_match_all('/<(?:div.+|br)>(.+?)<br>(.+?)<a href="tel:(.+)"/im', $body, $match, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
$all_data = [];
foreach ($match as $address_block) {
    $shop_data = [];

    $id_pos_start = strrpos($body, 'id="section-', -($total_len - $address_block[0][1]));
    $id_pos_end = strpos($body, '">', $id_pos_start);
    $id_block = substr($body, $id_pos_start + 4, $id_pos_end - $id_pos_start - 4);

    $title_pos_start = strrpos($body, '<h2', -($total_len - $address_block[0][1]));
    $title_pos_end = strpos($body, '</h2>', $title_pos_start);
    $title_block = substr($body, $title_pos_start, $title_pos_end - $title_pos_start);
    // 表記の揺れが多すぎる!!
    if (preg_match('/<(?:strong|b)>[0-9]+(?:．|\.)(?P<name>.+)<\/(?:strong|b)>(?:.*)(?:（|\()(?P<genre>.+)(?:）|\))/', $title_block, $title_match) === 0) {
        if (preg_match('/<(?:strong|b)>[0-9]+(?:．|\.)(?P<name>.+)(?:.*)(?:（|\()(?P<genre>.+)(?:）|\))<\/(?:strong|b)>/', $title_block, $title_match) === 0) {
            if (preg_match('/<(?:strong|b)>[0-9]+(?:．|\.)(?P<genre>.+)「(?P<name>.+)」<\/(?:strong|b)>/', $title_block, $title_match) === 0) {
                if (preg_match('/<(?:strong|b)>[0-9]+(?:．|\.)(?P<name>.+)<\/(?:strong|b)>/', $title_block, $title_match) === 0) {
                    preg_match('/<(?:strong|b)>(?P<name>.+)<\/(?:strong|b)>/', $title_block, $title_match);
                }
            }
        }
    }
    $shop_data['type'] = 'Feature';
    $shop_data['id'] = _fw_mb_trim($id_block);
    $shop_data['name'] = _fw_mb_trim(strip_tags(html_entity_decode($title_match['name'])));
    $shop_data['genre'] = _fw_mb_trim(strip_tags(html_entity_decode($title_match['genre'])));
    $shop_data['address'] = _fw_mb_trim(strip_tags(html_entity_decode($address_block[1][0])));

    $shop_data['properties'] = [
        'popup' => '<a href="'.$source_url.'#'.$shop_data['id'].'" target="_blank">'.$shop_data['name'].'</a>'.($shop_data['genre']?'（'.$shop_data['genre'].'）':'')
        .'<br>'.$shop_data['address'],
    ];

    $query = http_build_query([
        'appid' => $app_id,
        'query' => $shop_data['address'],
        'recursive' => true,
        'results' => 1,
        'output' => 'json',
    ]);
    $json = json_decode(file_get_contents($yahoo_geo_url.'?'.$query), true);
    if (isset($json['Feature'][0]['Geometry']['Coordinates'])) {
        $arr = explode(',', $json['Feature'][0]['Geometry']['Coordinates']);
        $shop_data['geometry'] = [
            'type' => 'Point',
            'coordinates' => [_fw_mb_trim($arr[0]), _fw_mb_trim($arr[1])],
        ];
    }
    var_export($shop_data);
    $all_data[] = $shop_data;
}

$dt = new Datetime();
file_put_contents($output_filename, json_encode([
    'updatetime' => $dt->format('Y-m-d H:i:s'),
    'data' => $all_data
]));

exit(0);
