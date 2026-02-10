<?php

use Rhymix\Framework\HTTP;

class youtube_content extends WidgetHandler
{
    public function proc($args)
    {
        // 위젯 옵션 파싱 및 기본값 설정
        $channel_id = trim($args->channel_id ?? '');
        $max_items = (int)($args->video_count ?? ($args->max_videos ?? ($args->max_items ?? 4)));
        if ($max_items <= 0) {
            $max_items = 4;
        }
        $feed_max_items = 15;
        if ($max_items > $feed_max_items) {
            $max_items = $feed_max_items;
        }

        $sort_order = $this->normalizeSortOrder($args->sort_order ?? 'recent');

        $cache_ttl_minutes = (int)($args->cache_ttl ?? 10);
        if ($cache_ttl_minutes <= 0) {
            $cache_ttl_minutes = 10;
        }
        $cache_ttl = $cache_ttl_minutes * 60;

        $widget_info = new stdClass();
        $widget_info->title = $args->title ?? '';
        $widget_info->video_count = $max_items;
        $widget_info->max_videos = $max_items;
        $widget_info->max_items = $max_items;
        $widget_info->sort_order = $sort_order;
        $widget_info->open_new_tab = ($args->open_new_tab ?? 'Y') === 'Y';
        Context::set('widget_info', $widget_info);

        $ytb_data = new stdClass();
        $ytb_data->channel_id = $channel_id;
        $ytb_data->channel_title = '';
        $ytb_data->items = [];
        $ytb_data->fetched_at = 0;
        $ytb_data->error = '';

        // 필수 설정 누락 시 즉시 종료
        if ($channel_id === '') {
            $ytb_data->error = '채널 ID가 설정되어 있지 않습니다.';
            Context::set('ytb_data', $ytb_data);
            return $this->renderTemplate($args);
        }

        // RSS 피드 수집 및 파싱 및 캐시 처리
        $cache = CacheHandler::getInstance('object');
        $cache_key = $cache->getGroupKey('youtube_content', 'channel:' . $channel_id);

        $cached = $cache->get($cache_key);
        if ($cached !== false && is_object($cached)) {
            $ytb_data = $cached;
        } else {
            $ytb_data = $this->fetchFeed($channel_id);
            if (!$ytb_data->error && $cache->isSupport()) {
                $cache->put($cache_key, $ytb_data, $cache_ttl);
            }
        }

        if (!empty($ytb_data->items)) {
            $this->sortItems($ytb_data->items, $sort_order);
        }

        // RSS 최대 개수 범위 내에서 표시용 아이템 선택
        if ($max_items > 0 && !empty($ytb_data->items)) {
            $ytb_data->items = array_slice($ytb_data->items, 0, $max_items);
        }

        Context::set('ytb_data', $ytb_data);
        return $this->renderTemplate($args);
    }

    protected function renderTemplate($args)
    {
        // 선택한 스킨 템플릿 렌더링
        $tpl_path = sprintf('%sskins/%s', $this->widget_path, $args->skin);
        $tpl_file = 'ytbcontent';

        $oTemplate = TemplateHandler::getInstance();
        return $oTemplate->compile($tpl_path, $tpl_file);
    }

    protected function fetchFeed($channel_id)
    {
        // 유튜브 RSS 수집 및 표준화 데이터 파싱
        $ytb_data = new stdClass();
        $ytb_data->channel_id = $channel_id;
        $ytb_data->channel_title = '';
        $ytb_data->items = [];
        $ytb_data->fetched_at = 0;
        $ytb_data->error = '';

        $url = 'https://www.youtube.com/feeds/videos.xml?channel_id=' . rawurlencode($channel_id);
        $response = HTTP::get($url, null, ['User-Agent' => 'Rhymix YouTube Widget'], [], ['timeout' => 5]);

        if ($response->getStatusCode() !== 200) {
            $ytb_data->error = '유튜브 RSS를 가져오지 못했습니다.';
            return $ytb_data;
        }

        $body = (string)$response->getBody();
        if ($body === '') {
            $ytb_data->error = '유튜브 RSS 응답이 비어 있습니다.';
            return $ytb_data;
        }

        $previous_errors = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous_errors);

        if (!$xml) {
            $ytb_data->error = '유튜브 RSS 파싱에 실패했습니다.';
            return $ytb_data;
        }

        $namespaces = $xml->getNamespaces(true);
        $media_ns = $namespaces['media'] ?? 'http://search.yahoo.com/mrss/';
        $yt_ns = $namespaces['yt'] ?? 'http://www.youtube.com/xml/schemas/2015';

        $ytb_data->channel_title = trim((string)$xml->title);

        $items = [];
        foreach ($xml->entry as $entry) {
            $entry_ns = $entry->getNamespaces(true);
            $entry_media_ns = $entry_ns['media'] ?? $media_ns;
            $entry_yt_ns = $entry_ns['yt'] ?? $yt_ns;

            $media_group = null;
            $media = $entry->children($entry_media_ns);
            if ($media && isset($media->group)) {
                $media_group = $media->group;
            }

            $thumbnail = '';
            if ($media_group && isset($media_group->thumbnail)) {
                $thumb_attr = $media_group->thumbnail[0]->attributes();
                if ($thumb_attr && isset($thumb_attr['url'])) {
                    $thumbnail = (string)$thumb_attr['url'];
                }
            }

            $description = '';
            if ($media_group && isset($media_group->description)) {
                $description = trim((string)$media_group->description);
                $description = trim(htmlspecialchars_decode(strip_tags($description)));
            }

            $video_id = '';
            $yt_entry = $entry->children($entry_yt_ns);
            if ($yt_entry && isset($yt_entry->videoId)) {
                $video_id = trim((string)$yt_entry->videoId);
            }

            $link = '';
            if (isset($entry->link)) {
                foreach ($entry->link as $link_node) {
                    $rel = (string)$link_node['rel'];
                    if ($rel === '' || $rel === 'alternate') {
                        $link = (string)$link_node['href'];
                        break;
                    }
                }
            }
            if ($link === '' && $video_id !== '') {
                $link = 'https://www.youtube.com/watch?v=' . rawurlencode($video_id);
            }

            $published_raw = trim((string)$entry->published);
            $published_ts = $published_raw !== '' ? strtotime($published_raw) : 0;
            $published_datetime = $published_ts ? date('Y-m-d H:i', $published_ts) : '';

            $community = ($media_group && isset($media_group->community)) ? $media_group->community : null;

            $view_count = 0;
            if ($community && isset($community->statistics)) {
                $stats_attr = $community->statistics->attributes();
                if ($stats_attr && isset($stats_attr['views'])) {
                    $view_count = (int)$stats_attr['views'];
                }
            }

            $like_count = 0;
            if ($community && isset($community->starRating)) {
                $rating_attr = $community->starRating->attributes();
                if ($rating_attr && isset($rating_attr['count'])) {
                    $like_count = (int)$rating_attr['count'];
                }
            }

            $items[] = (object)[
                'title' => trim((string)$entry->title),
                'link' => $link,
                'video_id' => $video_id,
                'thumbnail' => $thumbnail,
                'description' => $description,
                'published_datetime' => $published_datetime,
                'published_ts' => $published_ts,
                'view_count' => $view_count,
                'like_count' => $like_count,
            ];
        }

        $ytb_data->items = $items;
        $ytb_data->fetched_at = time();
        return $ytb_data;
    }

    protected function sortItems(array &$items, string $sort_order)
    {
        switch ($sort_order) {
            case 'views':
                usort($items, function ($a, $b) {
                    $result = ($b->view_count ?? 0) <=> ($a->view_count ?? 0);
                    if ($result !== 0) {
                        return $result;
                    }
                    return ($b->published_ts ?? 0) <=> ($a->published_ts ?? 0);
                });
                break;
            case 'likes':
                usort($items, function ($a, $b) {
                    $result = ($b->like_count ?? 0) <=> ($a->like_count ?? 0);
                    if ($result !== 0) {
                        return $result;
                    }
                    $result = ($b->view_count ?? 0) <=> ($a->view_count ?? 0);
                    if ($result !== 0) {
                        return $result;
                    }
                    return ($b->published_ts ?? 0) <=> ($a->published_ts ?? 0);
                });
                break;
            case 'random':
                shuffle($items);
                break;
            case 'recent':
            default:
                usort($items, function ($a, $b) {
                    return ($b->published_ts ?? 0) <=> ($a->published_ts ?? 0);
                });
                break;
        }
    }

    protected function normalizeSortOrder($sort_order)
    {
        $value = strtolower(trim((string)$sort_order));
        $allowed = ['recent', 'views', 'likes', 'random'];
        return in_array($value, $allowed, true) ? $value : 'recent';
    }
}

?>
