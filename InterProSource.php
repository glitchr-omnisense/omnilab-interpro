<?php

namespace Omnilab\InterPro;

use Omnilab\Exception\NotSupportedException;
use Omnilab\Model\Domain;
use Omnilab\Model\Entity;
use Omnilab\Model\Identifier;
use Omnilab\Model\Identifiers;
use Omnilab\Model\Property;
use Omnilab\Model\Quantity;
use Omnilab\Model\Scheme;
use Omnilab\Source\Capability;
use Omnilab\Source\HttpSource;
use Omnilab\Source\Page;
use Omnilab\Source\Query;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * InterPro, the EBI's classification of protein families, domains and
 * sites, through its API: an InterPro entry ("IPR000971", globin) or a
 * Pfam family ("PF00042") - its name, type, description, member
 * signatures, GO terms, how many proteins and structures it covers, its
 * Pfam clan, its representative structure, its overlapping entries and its
 * place in InterPro's hierarchy -; a UniProt protein ("P68871") with the
 * InterPro entries and the Pfam families it matches, each by its type
 * (domain, family, homologous superfamily, repeat, site) as related things,
 * and where on the sequence; a search of InterPro entries by text.
 *
 * No key, no account, no published rate: no throttle by default. One call
 * an entry, two a protein (the protein, its entries), one a search page;
 * over HTTP/1.1, InterPro's 204 (nothing found) being refused by curl over
 * HTTP/2.
 */
final class InterProSource extends HttpSource
{
    public const BASE_URI = 'https://www.ebi.ac.uk/interpro/api/';

    /** The most entries a search page holds (the API's own cap is 200). */
    public const MAX_LIMIT = 200;

    /** InterPro's names of its member databases. */
    private const MEMBERS = [
        'pfam' => 'Pfam', 'profile' => 'PROSITE profiles', 'prosite' => 'PROSITE patterns', 'prints' => 'PRINTS', 'smart' => 'SMART',
        'cdd' => 'CDD', 'cathgene3d' => 'CATH-Gene3D', 'ssf' => 'SUPERFAMILY', 'panther' => 'PANTHER', 'pirsf' => 'PIRSF',
        'ncbifam' => 'NCBIfam', 'hamap' => 'HAMAP', 'sfld' => 'SFLD', 'pirsr' => 'PIRSR', 'antifam' => 'AntiFam',
    ];

    /** UniProt's levels of protein existence, as UniProt words them. */
    private const EXISTENCE = [1 => 'Evidence at protein level', 2 => 'Evidence at transcript level', 3 => 'Inferred from homology', 4 => 'Predicted', 5 => 'Uncertain'];

    public function __construct(
        HttpClientInterface $http,
        string $baseUri = self::BASE_URI,
        array $headers = [],
        float $throttle = 0.0,
    ) {
        // HTTP/1.1: InterPro answers an empty result with a 204 that carries a Content-Length, which curl refuses over HTTP/2 ("Invalid HTTP header field").
        parent::__construct($http->withOptions(['http_version' => '1.1']), $baseUri, $headers, $throttle);
    }

    public function getName(): string
    {
        return 'interpro';
    }

    public function capabilities(): array
    {
        return [Capability::ENTITY, Capability::SEARCH];
    }

    /**
     * An InterPro entry ("IPR000971", "interpro:IPR000971", its page), a
     * Pfam family ("PF00042", versioned or not) or a UniProt protein
     * ("P68871", "uniprot:P68871", its page);
     * another base's identifier is refused before any call; null when
     * InterPro has no such entry or protein.
     */
    public function entity(Identifier|string $id): ?Entity
    {
        $identifier = self::identify($id, Scheme::INTERPRO, Scheme::PFAM, Scheme::UNIPROT) ?? throw NotSupportedException::identifier('interpro', (string) $id);
        if (Scheme::UNIPROT === $identifier->scheme) {
            $protein = $this->getJson('protein/uniprot/'.rawurlencode($identifier->value))['metadata'] ?? null;
            if (!\is_array($protein)) {
                return null;
            }
            $entries = $this->getJson('entry/all/protein/uniprot/'.rawurlencode($identifier->value), ['page_size' => 200])['results'] ?? [];

            return $this->protein($protein, $entries);
        }
        $database = Scheme::PFAM === $identifier->scheme ? 'pfam' : 'interpro';
        $entry = $this->getJson('entry/'.$database.'/'.rawurlencode($identifier->value))['metadata'] ?? null;

        return \is_array($entry) ? $this->entry($entry) : null;
    }

    /**
     * InterPro entries whose name or text holds the words, in InterPro's
     * order (by accession: the API does not rank); a domain other than
     * biology answers nothing. An entity of a page holds its name, type,
     * member signatures and GO terms: entity() reads it whole.
     */
    public function search(Query $query): Page
    {
        if (null !== $query->domain && Domain::BIOLOGY !== $query->domain) {
            return new Page();
        }
        $text = trim((string) preg_replace('~\s+~u', ' ', $query->text));
        if ('' === $text) {
            return new Page();
        }
        $data = $this->getJson('entry/interpro', ['search' => $text, 'page_size' => max(1, min(self::MAX_LIMIT, $query->limit)), 'cursor' => $query->cursor]);
        if (null === $data) {
            return new Page([], null, 0); // 204: nothing matched
        }
        $items = [];
        foreach ($data['results'] ?? [] as $result) {
            if (\is_array($result['metadata'] ?? null) && null !== ($entity = $this->entry($result['metadata']))) {
                $items[] = $entity;
            }
        }
        $next = null;
        if (\is_string($data['next'] ?? null)) {
            parse_str((string) parse_url($data['next'], \PHP_URL_QUERY), $params);
            $next = isset($params['cursor']) ? (string) $params['cursor'] : null;
        }

        return new Page($items, $next, isset($data['count']) ? (int) $data['count'] : null);
    }

    /** @param array<string, mixed> $entry an entry's metadata */
    private function entry(array $entry): ?Entity
    {
        $id = Identifier::tryOf('pfam' === ($entry['source_database'] ?? null) ? Scheme::PFAM : Scheme::INTERPRO, $entry['accession'] ?? null);
        if (null === $id) {
            return null;
        }
        $properties = [];
        $add = static function (string $key, string $label, mixed $value) use (&$properties): void {
            if (null !== $value && '' !== $value) {
                $properties[] = new Property($key, $label, $value, 'interpro');
            }
        };
        $type = \is_string($entry['type'] ?? null) ? strtolower($entry['type']) : null;
        $add('entry_type', 'Entry type', null === $type ? null : str_replace('_', ' ', $type));
        $add('database', 'Database', 'pfam' === ($entry['source_database'] ?? null) ? 'Pfam' : 'InterPro');
        foreach ((array) ($entry['member_databases'] ?? []) as $database => $signatures) {
            foreach ((array) $signatures as $accession => $name) {
                $add('member', 'Member signature', $accession.' '.$name.' ('.(self::MEMBERS[$database] ?? $database).')');
            }
        }
        $go = [];
        foreach ((array) ($entry['go_terms'] ?? []) as $term) {
            if (null !== ($identifier = Identifier::tryOf(Scheme::GO, $term['identifier'] ?? null))) {
                $go[] = $identifier;
                $add('go_term', 'GO term', self::text($term['name'] ?? null).' ('.$identifier->display().(isset($term['category']['name']) ? ', '.str_replace('_', ' ', (string) $term['category']['name']) : '').')');
            }
        }
        $counters = (array) ($entry['counters'] ?? []);
        foreach (['proteins' => 'Proteins', 'structures' => 'Structures', 'taxa' => 'Taxa', 'proteomes' => 'Proteomes', 'domain_architectures' => 'Domain architectures'] as $key => $label) {
            $add($key, $label, isset($counters[$key]) && is_numeric($counters[$key]) ? (int) $counters[$key] : null);
        }
        $add('alphafold_models', 'AlphaFold models', isset($counters['structural_models']['alphafold']) ? (int) $counters['structural_models']['alphafold'] : null);
        $add('references', 'Literature references', \is_array($entry['literature'] ?? null) ? \count($entry['literature']) : null);
        $add('clan', 'Pfam clan', isset($entry['set_info']['accession']) ? $entry['set_info']['accession'].' '.($entry['set_info']['name'] ?? '') : null);
        if (\array_key_exists('is_llm', $entry)) {
            $add('ai_generated', 'Description written by a language model', (bool) $entry['is_llm']);
        }

        $related = ['go' => new Identifiers($go)];
        $related['member'] = new Identifiers(array_map(static fn (string $accession) => Identifier::tryOf(Scheme::PFAM, $accession), array_keys((array) ($entry['member_databases']['pfam'] ?? []))));
        $related['integrated'] = Identifiers::of(Identifier::tryOf(Scheme::INTERPRO, $entry['integrated'] ?? null));
        $related['structure'] = Identifiers::of(Identifier::tryOf(Scheme::PDB, $entry['representative_structure']['accession'] ?? null));
        $related['overlap'] = new Identifiers(array_map(static fn (mixed $o) => \is_array($o) ? Identifier::tryOf(Scheme::INTERPRO, $o['accession'] ?? null) : null, (array) ($entry['overlaps_with'] ?? [])));
        [$parent, $children] = (\is_array($entry['hierarchy'] ?? null) ? self::family($entry['hierarchy'], $id->value) : null) ?? [null, []];
        $related['parent'] = Identifiers::of($parent);
        $related['child'] = new Identifiers($children);

        $texts = [];
        foreach ((array) ($entry['description'] ?? []) as $description) {
            $text = \is_array($description) ? ($description['text'] ?? null) : $description;
            // "[[cite:PUB00035866]]": the reference marks, left out with their brackets.
            if (\is_string($text) && null !== ($text = self::text((string) preg_replace('~\s*\[(?:\[cite:[^\]]+\](?:,\s*)?)+\]~', '', $text)))) {
                $texts[] = $text;
            }
        }
        if (!$texts && \is_array($entry['wikipedia'][0] ?? null) && null !== ($extract = self::text($entry['wikipedia'][0]['extract'] ?? null))) {
            $texts[] = $extract;
        }
        $label = self::text($entry['name']['name'] ?? (\is_string($entry['name'] ?? null) ? $entry['name'] : null)) ?? $id->value;
        $short = self::text($entry['name']['short'] ?? null);

        return new Entity(
            id: $id,
            label: $label,
            domain: Domain::BIOLOGY,
            kind: $type ? str_replace('_', ' ', $type) : null,
            synonyms: null !== $short && $short !== $label ? [$short] : [],
            description: $texts ? implode("\n\n", $texts) : null,
            properties: $properties,
            url: $id->url(),
            source: 'interpro',
            related: array_filter($related, static fn (Identifiers $ids) => \count($ids) > 0),
        );
    }

    /**
     * @param array<string, mixed>       $protein a protein's metadata
     * @param list<array<string, mixed>> $entries the entries it matches, with their locations
     */
    private function protein(array $protein, array $entries): ?Entity
    {
        $id = Identifier::tryOf(Scheme::UNIPROT, $protein['accession'] ?? null);
        if (null === $id) {
            return null;
        }
        $properties = [];
        $add = static function (string $key, string $label, mixed $value) use (&$properties): void {
            if (null !== $value && '' !== $value) {
                $properties[] = new Property($key, $label, $value, 'interpro');
            }
        };
        $add('entry_name', 'Entry name', self::text($protein['id'] ?? null));
        $add('gene', 'Gene', self::text($protein['gene'] ?? null));
        // UniProt's keys and words, so that a merge with omnilab/uniprot sets the two side by side.
        $add('organism', 'Organism', self::text($protein['source_organism']['scientificName'] ?? null));
        $add('sequence_length', 'Sequence length', is_numeric($protein['length'] ?? null) ? new Quantity((int) $protein['length'], 'aa') : null);
        $add('reviewed', 'Reviewed (Swiss-Prot)', isset($protein['source_database']) ? 'reviewed' === $protein['source_database'] : null);
        $add('protein_existence', 'Protein existence', self::EXISTENCE[(int) ($protein['protein_evidence'] ?? 0)] ?? null);
        $add('fragment', 'Fragment', isset($protein['is_fragment']) ? (bool) $protein['is_fragment'] : null);
        $add('proteome', 'Proteome', self::text($protein['proteome'] ?? null));
        $add('in_alphafold', 'Has an AlphaFold model', isset($protein['in_alphafold']) ? (bool) $protein['in_alphafold'] : null);
        $add('structures', 'Structures', is_numeric($protein['counters']['structures'] ?? null) ? (int) $protein['counters']['structures'] : null);

        $related = [];
        $matches = [];
        foreach ($entries as $result) {
            $entry = $result['metadata'] ?? null;
            $database = \is_array($entry) ? ($entry['source_database'] ?? null) : null;
            $scheme = match ($database) {
                'interpro' => Scheme::INTERPRO,
                'pfam' => Scheme::PFAM,
                default => null,
            };
            $identifier = null === $scheme ? null : Identifier::tryOf($scheme, $entry['accession'] ?? null);
            if (null === $identifier) {
                continue; // the other member databases (CDD, PANTHER, CATH...) have no scheme here
            }
            $type = strtolower((string) ($entry['type'] ?? 'other'));
            $related[$type][] = $identifier;
            $where = [];
            foreach ($result['proteins'][0]['entry_protein_locations'] ?? [] as $location) {
                foreach ($location['fragments'] ?? [] as $fragment) {
                    if (isset($fragment['start'], $fragment['end'])) {
                        $where[] = $fragment['start'].'-'.$fragment['end'];
                    }
                }
            }
            $matches[] = [
                'pfam' === $database ? 'pfam' : $type,
                'pfam' === $database ? 'Pfam '.str_replace('_', ' ', $type) : ucfirst(str_replace('_', ' ', $type)),
                self::text($entry['name'] ?? null).' ('.$identifier->value.')'.($where ? ': '.implode(', ', $where) : ''),
            ];
        }
        // InterPro's entries first, then Pfam's.
        usort($matches, static fn (array $a, array $b) => ('pfam' === $a[0]) <=> ('pfam' === $b[0]));
        foreach ($matches as [$key, $label, $value]) {
            $add($key, $label, $value);
        }
        $go = [];
        foreach ((array) ($protein['go_terms'] ?? []) as $term) {
            if (null !== ($identifier = Identifier::tryOf(Scheme::GO, $term['identifier'] ?? null))) {
                $go[] = $identifier;
                $add('go_term', 'GO term', self::text($term['name'] ?? null).' ('.$identifier->display().(isset($term['category']['name']) ? ', '.str_replace('_', ' ', (string) $term['category']['name']) : '').')');
            }
        }
        $related = array_map(static fn (array $ids) => new Identifiers($ids), $related);
        $related['go'] = new Identifiers($go);
        $related['organism'] = Identifiers::of(Identifier::tryOf(Scheme::NCBI_TAXON, $protein['source_organism']['taxId'] ?? null));
        $descriptions = array_values(array_filter(array_map(static fn (mixed $d) => \is_string($d) ? self::text((string) preg_replace('~\s*\((?:PubMed|Probable|By similarity)[^)]*\)~', '', $d)) : null, (array) ($protein['description'] ?? []))));

        return new Entity(
            id: $id,
            label: self::text($protein['name'] ?? null) ?? $id->value,
            domain: Domain::BIOLOGY,
            kind: 'protein',
            description: $descriptions ? implode('. ', array_map(static fn (string $d) => rtrim($d, '.'), $descriptions)).'.' : null,
            properties: $properties,
            url: 'https://www.ebi.ac.uk/interpro/protein/UniProt/'.rawurlencode($id->value).'/',
            source: 'interpro',
            related: array_filter($related, static fn (Identifiers $ids) => \count($ids) > 0),
        );
    }

    /**
     * An entry's parent and children in InterPro's hierarchy (the tree from
     * its root); null when the tree does not hold it.
     *
     * @param array<string, mixed> $node
     *
     * @return array{0: ?Identifier, 1: list<Identifier>}|null
     */
    private static function family(array $node, string $accession, ?string $parent = null): ?array
    {
        if (($node['accession'] ?? null) === $accession) {
            $children = array_map(static fn (mixed $child) => \is_array($child) ? Identifier::tryOf(Scheme::INTERPRO, $child['accession'] ?? null) : null, (array) ($node['children'] ?? []));

            return [Identifier::tryOf(Scheme::INTERPRO, $parent), array_values(array_filter($children))];
        }
        foreach ((array) ($node['children'] ?? []) as $child) {
            if (\is_array($child) && null !== ($found = self::family($child, $accession, $node['accession'] ?? null))) {
                return $found;
            }
        }

        return null;
    }
}
