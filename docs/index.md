# omnilab/interpro

## Installation

```sh
composer require omnilab/interpro
```

```php
use Omnilab\InterPro\InterProSourceFactory;

$interpro = (new InterProSourceFactory($httpClient))->create([
    'mailto' => 'support@glitchr.io',  // optional
]);
```

## The schemes

An InterPro entry is named in the core's `interpro` scheme (`IPR000971`), a Pfam family in `pfam`
(`PF00042`, its version dropped: `PF00042.27`); both are read from their shapes, their prefixes and
their pages in InterPro (`https://www.ebi.ac.uk/interpro/entry/InterPro/IPR000971/`,
`.../entry/pfam/PF00042/`). A protein is named by its UniProt accession (`P68871`,
`uniprot:P68871`): an accession alone is read as one, none of the other schemes this source reads
having its shape.

## Which API

`https://www.ebi.ac.uk/interpro/api/` (InterPro 110.0 on 2026-10-15, the `InterPro-Version`
header):

| Method | Calls |
|---|---|
| `entity()`, an entry | `entry/interpro/{IPR}` or `entry/pfam/{PF}` |
| `entity()`, a protein | `protein/uniprot/{accession}`, then `entry/all/protein/uniprot/{accession}?page_size=200` (every entry it matches, with its locations) |
| `search()` | `entry/interpro?search=...&page_size=...&cursor=...` - the next page by the `cursor` of the answer's `next` link |

Calls go over HTTP/1.1: InterPro answers "nothing found" with a 204 that carries a
`Content-Length`, which curl refuses over HTTP/2 ("Invalid HTTP header field", met on 2026-10-15);
over HTTP/1.1 it is read as nothing. An unknown entry, protein or search is that 204: `null`, an
empty page. An identifier of a base InterPro does not read here (ChEBI, GO, NCBI Gene...) is
refused before any call.

## What an entity holds

An entry: `id` (`interpro:IPR000971`, `pfam:PF00042`), `label` (its name), `synonyms` (its short
name when it differs), `kind` (its type: `domain`, `family`, `homologous superfamily`, `repeat`,
`conserved site`, `active site`, `binding site`, `ptm`), `description` (InterPro's text, tags and
reference marks `[[cite:PUB...]]` taken off; for a Pfam family without one, Wikipedia's extract
InterPro gives), `url`, and:

| Key | API | Value |
|---|---|---|
| `entry_type` | type | `domain` |
| `database` | source_database | `InterPro`, `Pfam` |
| `member` (one each) | member_databases | `PF00042 Globin (Pfam)`, `PS01033 Globin domain profile (PROSITE profiles)` |
| `go_term` (one each) | go_terms | `heme binding (GO:0020037, molecular function)` |
| `proteins`, `structures`, `taxa`, `proteomes`, `domain_architectures`, `alphafold_models` | counters | how many |
| `references` | literature | how many |
| `clan` | set_info (Pfam) | `CL0090 Globin` |
| `ai_generated` | is_llm | whether InterPro's description was written by a language model |

`related`: `member` (the Pfam families an InterPro entry integrates), `integrated` (the InterPro
entry a Pfam family is integrated into), `go`, `structure` (the representative PDB entry),
`overlap` (the entries it overlaps), `parent` and `child` (InterPro's hierarchy: IPR002339, the pi
hemoglobins, is a child of IPR002338, the alpha-type ones).

A protein: `id` (`uniprot:P68871`), `label` (its name), `kind` `protein`, `description` (UniProt's
function, its evidence marks taken off), `url` (its page in InterPro), and `entry_name`
(`HBB_HUMAN`), `gene`, `organism`, `sequence_length` (`147 aa`), `reviewed`,
`protein_existence`, `fragment`, `proteome`, `in_alphafold`, `structures` - UniProt's keys and
words where omnilab/uniprot has them, so that a merge sets both side by side -; one property per
entry it matches, its key the entry's type and its value where it lies (`domain`: `Globin
(IPR000971): 3-147`; `family`, `homologous_superfamily`...; `pfam`: `Globin (PF00042): 27-142`),
InterPro's first; `go_term` (one each). `related`: by the entries' types - `domain`
(`interpro:IPR000971`, `pfam:PF00042`), `family`, `homologous_superfamily`, `repeat`,
`conserved_site`... -, `go`, `organism`. The other member databases (CDD, PANTHER, CATH-Gene3D,
SUPERFAMILY, PRINTS, PROSITE) are left out: no scheme of the core names them.

## The search

InterPro's text search over its entries, in InterPro's order: by accession, not ranked
("globin" gives Uteroglobin first, then Globin; 47 entries on 2026-10-15). An entity of a page holds
its name, type, member signatures and GO terms: `entity()` reads it whole. A domain other than
biology answers nothing.

## The Collector: hemoglobin beta from UniProt

```php
$collector->collect('uniprot', 'P68871', 1, ['interpro']);
```

Against the real services on 2026-10-15: three calls (UniProt's entry, InterPro's protein and its
entries) in 3.3 to 4.1 s, `sources` = uniprot, interpro; both sequence lengths (`147 aa`
[uniprot], `147 aa` [interpro]), the domains, families and superfamilies related.

## What the API does not give here

The member databases' own entries (CDD, PANTHER...), the proteins of an entry (`protein/entry/...`:
tens of thousands), domain architectures, taxonomy and proteome breakdowns, structures and AlphaFold
models of an entry, InterPro's sets (Pfam clans as entities), the HMMs and alignments, and the
sequence's own features (InterPro's `residue` endpoints).

## Verified, and not

Against the real service on 2026-10-15 (InterPro 110.0): the globin domain IPR000971 (35,483
proteins, 1,461 structures, its Pfam member, heme binding), the beta-type hemoglobins IPR002337, the
pi hemoglobins IPR002339 under the alpha-type ones, Pfam's PF00042 (clan CL0090, integrated into
IPR000971); hemoglobin beta P68871 and its six InterPro and Pfam matches; an unknown entry and an
unknown protein (204); the search for "globin" in two pages of three; the Collector's walk above.
The fixtures in `Tests/Fixtures/` are its answers. Each call took 1 to 5 s.

Not verified: a rate limit (none published, none met); a protein matching more than 200 entries
(one page is read); the licence's text (its page is rendered by a script).
