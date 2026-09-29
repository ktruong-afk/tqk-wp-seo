<?php
namespace TQK_WPSEO\Schema;

defined('ABSPATH') || exit;

/**
 * S3 — Schema graph container. One <script> with @graph, validated nodes only.
 */
class Graph {

    private array $nodes = [];

    /**
     * Adds a node. A node whose @id is already in the graph is MERGED into
     * it (existing non-empty values win, gaps are filled): two nodes with
     * one @id but different properties confuse validators and Google.
     */
    public function add(array $node): self {
        if (($node['@type'] ?? '') === '') {
            return $this;
        }
        $id = (string) ($node['@id'] ?? '');
        if ($id !== '') {
            foreach ($this->nodes as $i => $existing) {
                if (($existing['@id'] ?? '') === $id) {
                    foreach ($node as $k => $v) {
                        if (!isset($existing[$k]) || $existing[$k] === '' || $existing[$k] === []) {
                            $existing[$k] = $v;
                        }
                    }
                    $this->nodes[$i] = $existing;
                    return $this;
                }
            }
        }
        $this->nodes[] = $node;
        return $this;
    }

    /** True when a node with this @id exists. */
    public function has(string $id): bool {
        foreach ($this->nodes as $n) {
            if (($n['@id'] ?? '') === $id) {
                return true;
            }
        }
        return false;
    }

    /**
     * Drops {"@id": ...} references to nodes that are not in the graph
     * (e.g. a breadcrumb on a page that has none).
     */
    public function prune_refs(): self {
        foreach ($this->nodes as $i => $n) {
            foreach ($n as $k => $v) {
                if (is_array($v) && count($v) === 1 && isset($v['@id']) && !$this->has((string) $v['@id'])) {
                    unset($this->nodes[$i][$k]);
                }
            }
        }
        return $this;
    }

    /** @return array */
    public function nodes(): array {
        return $this->nodes;
    }

    public function render(): string {
        if ($this->nodes === []) {
            return '';
        }
        $json = wp_json_encode([
            '@context' => 'https://schema.org',
            '@graph' => $this->nodes,
        ]);
        return '<script type="application/ld+json">' . $json . "</script>\n";
    }
}
