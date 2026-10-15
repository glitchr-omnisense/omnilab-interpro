<?php

namespace Omnilab\InterPro\Tests;

use Omnilab\Exception\NotSupportedException;
use Omnilab\Exception\ProviderException;
use Omnilab\Exception\UnavailableException;
use Omnilab\InterPro\InterProSource;
use Omnilab\InterPro\InterProSourceFactory;
use Omnilab\Model\Domain;
use Omnilab\Model\Identifier;
use Omnilab\Model\Quantity;
use Omnilab\Model\Scheme;
use Omnilab\Source\Capability;
use Omnilab\Source\Query;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Fixtures recorded from www.ebi.ac.uk/interpro/api on 2026-10-15: the
 * globin domain (IPR000971), the beta-type hemoglobin family (IPR002337),
 * the pi hemoglobin family (IPR002339, a child of the alpha-type one), the
 * globin Pfam family (PF00042); hemoglobin beta (UniProt P68871) and the
 * entries it matches; the search for "globin" in two pages of three (47
 * matches). An unknown entry or protein is a 204, no content.
 */
final class InterProSourceTest extends TestCase
{
    /** @var list<string> the paths asked, with their queries */
    private array $calls = [];

    private function source(?MockResponse $response = null): InterProSource
    {
        $http = new MockHttpClient(function (string $method, string $url) use ($response): MockResponse {
            $path = (string) parse_url($url, \PHP_URL_PATH);
            self::assertStringStartsWith('/interpro/api/', $path);
            $raw = (string) parse_url($url, \PHP_URL_QUERY);
            $this->calls[] = $call = substr($path, \strlen('/interpro/api/')).('' === $raw ? '' : '?'.rawurldecode($raw));
            if ($response) {
                return $response;
            }
            $fixture = match ($call) {
                'entry/interpro/IPR000971' => 'entry-IPR000971.json',
                'entry/interpro/IPR002337' => 'entry-IPR002337.json',
                'entry/interpro/IPR002339' => 'entry-IPR002339.json',
                'entry/pfam/PF00042' => 'entry-PF00042.json',
                'protein/uniprot/P68871' => 'protein-P68871.json',
                'entry/all/protein/uniprot/P68871?page_size=200' => 'entries-P68871.json',
                'entry/interpro?search=globin&page_size=3' => 'search-globin-0.json',
                'entry/interpro?search=globin&page_size=3&cursor=cD1JUFIwMDEwMzI=' => 'search-globin-3.json',
                default => null,
            };

            return null === $fixture ? new MockResponse('', ['http_code' => 204]) : new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/'.$fixture));
        });
        $source = (new InterProSourceFactory($http))->create();
        self::assertInstanceOf(InterProSource::class, $source);

        return $source;
    }

    public function testTheGlobinDomain(): void
    {
        $entry = $this->source()->entity('IPR000971');

        self::assertSame('interpro:IPR000971', $entry->id->key());
        self::assertSame('Globin', $entry->label);
        self::assertSame(Domain::BIOLOGY, $entry->domain);
        self::assertSame('domain', $entry->kind);
        self::assertSame('https://www.ebi.ac.uk/interpro/entry/InterPro/IPR000971/', $entry->url);
        self::assertStringStartsWith('This entry covers most of the globin family of proteins', (string) $entry->description);
        self::assertStringNotContainsString('cite:', (string) $entry->description, 'the reference marks left out');
        self::assertStringContainsString('distributed in many organisms. Globins have evolved', (string) $entry->description);
        self::assertSame(['PS01033 Globin domain profile (PROSITE profiles)', 'PF00042 Globin (Pfam)'], array_map('strval', $entry->properties('member')));
        self::assertSame('heme binding (GO:0020037, molecular function)', $entry->value('go_term'));
        self::assertSame(35483, $entry->value('proteins'));
        self::assertSame(1461, $entry->value('structures'));
        self::assertSame(28026, $entry->value('alphafold_models'));
        self::assertSame(15, $entry->value('references'));
        self::assertFalse($entry->value('ai_generated'));
        self::assertSame(['pfam:PF00042'], $entry->related('member')->keys());
        self::assertSame(['go:0020037'], $entry->related('go')->keys());
        self::assertSame(['pdb:1H97'], $entry->related('structure')->keys());
        self::assertSame(['interpro:IPR012292', 'interpro:IPR009050'], $entry->related('overlap')->keys());
        self::assertSame([], $entry->related('parent')->keys(), 'a root of its own');
        self::assertSame(['interpro:IPR000971'], $entry->identifiers()->keys());
        self::assertSame(['entry/interpro/IPR000971'], $this->calls);
    }

    public function testAFamilyInTheHierarchy(): void
    {
        $pi = $this->source()->entity(Identifier::of(Scheme::INTERPRO, 'IPR002339'));

        self::assertSame('Hemoglobin, pi', $pi->label);
        self::assertSame(['Hemoglobin_pi'], $pi->synonyms);
        self::assertSame('family', $pi->kind);
        self::assertSame(['interpro:IPR002338'], $pi->related('parent')->keys(), 'the alpha-type hemoglobins');
        self::assertSame([], $pi->related('child')->keys());
    }

    public function testAPfamFamily(): void
    {
        $pfam = $this->source()->entity('PF00042.27');

        self::assertSame('pfam:PF00042', (string) $pfam->id);
        self::assertSame('domain', $pfam->kind);
        self::assertSame('Pfam', $pfam->value('database'));
        self::assertSame('CL0090 Globin', $pfam->value('clan'));
        self::assertSame(['interpro:IPR000971'], $pfam->related('integrated')->keys());
        self::assertSame(['pdb:2XKI'], $pfam->related('structure')->keys());
        self::assertStringStartsWith('The globins are a superfamily of heme-containing globular proteins', (string) $pfam->description, 'Wikipedia\'s, Pfam having none of its own here');
        self::assertSame('https://www.ebi.ac.uk/interpro/entry/pfam/PF00042/', $pfam->url);
        self::assertSame(['entry/pfam/PF00042'], $this->calls);
    }

    public function testHemoglobinBetaAndItsDomains(): void
    {
        $protein = $this->source()->entity(Identifier::of(Scheme::UNIPROT, 'P68871'));

        self::assertSame('uniprot:P68871', (string) $protein->id);
        self::assertSame('Hemoglobin subunit beta', $protein->label);
        self::assertSame('protein', $protein->kind);
        self::assertSame('HBB', $protein->value('gene'));
        self::assertSame('HBB_HUMAN', $protein->value('entry_name'));
        self::assertEquals(new Quantity(147, 'aa'), $protein->value('sequence_length'));
        self::assertSame('Homo sapiens', $protein->value('organism'));
        self::assertSame('Evidence at protein level', $protein->value('protein_existence'), 'UniProt\'s keys and words');
        self::assertTrue($protein->value('reviewed'));
        self::assertSame(348, $protein->value('structures'));
        self::assertStringStartsWith('Involved in oxygen transport from the lung to the various peripheral tissues. LVV-hemorphin-7', (string) $protein->description);
        self::assertSame(['interpro:IPR000971', 'pfam:PF00042'], $protein->related('domain')->keys());
        self::assertSame(['interpro:IPR002337', 'interpro:IPR050056'], $protein->related('family')->keys());
        self::assertSame(['interpro:IPR009050', 'interpro:IPR012292'], $protein->related('homologous_superfamily')->keys());
        self::assertSame(['ncbi.taxon:9606'], $protein->related('organism')->keys());
        self::assertCount(4, $protein->related('go'));
        self::assertSame('Globin (IPR000971): 3-147', $protein->value('domain'));
        self::assertSame('Globin (PF00042): 27-142', $protein->value('pfam'));
        self::assertSame('Pfam domain', $protein->property('pfam')->label);
        self::assertSame(['Hemoglobin, beta-type (IPR002337): 8-146', 'Hemoglobin and related oxygen transporters (IPR050056): 1-147'], array_map('strval', $protein->properties('family')));
        self::assertSame('oxygen binding (GO:0019825, molecular function)', $protein->value('go_term'));
        self::assertSame('https://www.ebi.ac.uk/interpro/protein/UniProt/P68871/', $protein->url);
        self::assertSame(['protein/uniprot/P68871', 'entry/all/protein/uniprot/P68871?page_size=200'], $this->calls);
    }

    public function testUnknownIsNull(): void
    {
        self::assertSame('uniprot:P68871', (string) $this->source()->entity('P68871')?->id, 'an accession alone: none of the other schemes it reads looks like one');
        $this->calls = [];
        self::assertNull($this->source()->entity('IPR999999'));
        self::assertNull($this->source()->entity('uniprot:Q9ZZZ9'));
        self::assertSame(['entry/interpro/IPR999999', 'protein/uniprot/Q9ZZZ9'], $this->calls, 'no entries asked for an unknown protein');
    }

    public function testAnotherBaseIsRefusedBeforeAnyCall(): void
    {
        foreach (['CHEBI:15422', 'GO:0005829', 'globin', Identifier::of(Scheme::NCBI_GENE, '3043')] as $id) {
            try {
                $this->source()->entity($id);
                self::fail(\sprintf('%s should be refused', $id));
            } catch (NotSupportedException) {
            }
        }
        self::assertSame([], $this->calls);
    }

    public function testASearchInPages(): void
    {
        $source = $this->source();
        $first = $source->search(new Query('globin', limit: 3));

        self::assertSame(['IPR000329', 'IPR000971', 'IPR001032'], array_map(static fn ($e) => $e->id->value, $first->items), 'by accession: the API does not rank');
        self::assertSame(['Uteroglobin', 'Globin', 'Leghaemoglobin-like'], array_map(static fn ($e) => $e->label, $first->items));
        self::assertSame(47, $first->total);
        self::assertSame('cD1JUFIwMDEwMzI=', $first->next);

        $second = $source->search(new Query('globin', limit: 3, cursor: $first->next));
        self::assertSame(['IPR001486', 'IPR002335', 'IPR002337'], array_map(static fn ($e) => $e->id->value, $second->items));

        $none = $source->search(new Query('zzqqxx'));
        self::assertCount(0, $none);
        self::assertSame(0, $none->total);
        self::assertCount(0, $source->search(new Query('globin', Domain::ASTRONOMY)));
        self::assertCount(3, $this->calls);
    }

    public function testErrors(): void
    {
        try {
            $this->source(new MockResponse('{"detail":"Not a valid filter"}', ['http_code' => 400]))->entity('IPR000971');
            self::fail('a 400');
        } catch (ProviderException $e) {
            self::assertSame(400, $e->status);
            self::assertStringContainsString('Not a valid filter', $e->getMessage());
        }
        $this->expectException(UnavailableException::class);
        $this->source(new MockResponse('', ['http_code' => 503]))->entity('IPR000971');
    }

    public function testTheFactory(): void
    {
        $factory = new InterProSourceFactory(new MockHttpClient());

        self::assertSame('interpro', $factory->getName());
        self::assertSame([Capability::ENTITY, Capability::SEARCH], $factory->create()->capabilities());
    }
}
