<?php

if (!defined('ABSPATH')) exit;

class TMW_Cluster_Link_Injector {
    private $cluster_service;
    private $linking_engine;

    public function __construct(TMW_Cluster_Service $cluster_service, TMW_Cluster_Linking_Engine $linking_engine) {
        $this->cluster_service = $cluster_service;
        $this->linking_engine = $linking_engine;
    }

    public function inject_missing_links($cluster_id) {
        $cluster_id = (int) $cluster_id;
        $max_links_per_post = 5;

        if ($cluster_id <= 0) {
            return [];
        }

        $analysis = $this->linking_engine->analyze_cluster($cluster_id);

        if (empty($analysis['missing_links']) || !is_array($analysis['missing_links'])) {
            return ['updated' => 0];
        }

        $updated = 0;
        $processed_pairs = [];

        $missing_links_by_source = [];

        foreach ($analysis['missing_links'] as $missing_link) {
            $from_id = isset($missing_link['from']) ? (int) $missing_link['from'] : 0;
            $to_id = isset($missing_link['to']) ? (int) $missing_link['to'] : 0;

            if ($from_id <= 0 || $to_id <= 0) {
                continue;
            }

            $missing_links_by_source[$from_id][] = $to_id;
        }

        foreach ($missing_links_by_source as $from_id => $target_ids) {
            $post = get_post($from_id);

            if (!$post) {
                continue;
            }

            if ($post->post_status !== 'publish') {
                continue;
            }

            if (!in_array($post->post_type, ['post', 'page'], true)) {
                continue;
            }

            $existing_links = substr_count($post->post_content, '<a href=');
            if ($existing_links >= $max_links_per_post) {
                continue;
            }

            foreach ($target_ids as $to_id) {
                $pair_key = $from_id . ':' . $to_id;
                if (isset($processed_pairs[$pair_key])) {
                    continue;
                }

                $target_url = get_permalink($to_id);
                $target_title = get_the_title($to_id);
                $anchor_text = $target_title;
                $anchor_link = '<a href="' . esc_url($target_url) . '">' . esc_html($anchor_text) . '</a>';
                $content = $post->post_content;

                if (strpos($content, $target_url) !== false) {
                    continue;
                }

                libxml_use_internal_errors(true);

                $dom = new DOMDocument('1.0', 'UTF-8');
                $wrapped_content = '<div>' . $post->post_content . '</div>';
                $dom->loadHTML('<?xml encoding="UTF-8">' . $wrapped_content);

                $xpath = new DOMXPath($dom);
                $paragraphs = $xpath->query('//p');

                $injected = false;

                foreach ($paragraphs as $p) {
                    if ($injected) {
                        break;
                    }

                    // Skip paragraphs that do not contain the target title.
                    if (strpos($p->textContent, $target_title) === false) {
                        continue;
                    }

                    foreach ($p->childNodes as $node) {
                        if ($node->nodeType === XML_TEXT_NODE &&
                            stripos($node->nodeValue, $target_title) !== false) {
                            $match_pos = stripos($node->nodeValue, $target_title);

                            if ($match_pos === false) {
                                continue;
                            }

                            $new_html = substr($node->nodeValue, 0, $match_pos)
                                . '<a href="' . esc_url($target_url) . '">' . esc_html($target_title) . '</a>'
                                . substr($node->nodeValue, $match_pos + strlen($target_title));

                            $fragment = $dom->createDocumentFragment();
                            $fragment->appendXML($new_html);
                            $p->replaceChild($fragment, $node);

                            $injected = true;
                            break;
                        }
                    }
                }

                if ($injected) {
                    $body = $dom->getElementsByTagName('div')->item(0);
                    $new_content = '';

                    foreach ($body->childNodes as $child) {
                        $new_content .= $dom->saveHTML($child);
                    }
                } else {
                    $new_content = $content . '<p>' . $anchor_link . '</p>';
                }

                libxml_clear_errors();

                $result = wp_update_post([
                    'ID' => $post->ID,
                    'post_content' => $new_content,
                ], true);

                if (!is_wp_error($result) && $result) {
                    $updated++;
                    $processed_pairs[$pair_key] = true;
                    break;
                }
            }
        }

        TMW_Main_Class::clear_cluster_cache($cluster_id);

        return [
            'updated' => $updated,
        ];
    }
}
