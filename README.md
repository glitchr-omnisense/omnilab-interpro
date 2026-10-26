# omnilab/interpro

InterPro for [omnisense/omnilab](https://github.com/glitchr-omnisense/omnilab): an InterPro entry
(`IPR000971`, globin) or a Pfam family (`PF00042`) with its name, type, description, member
signatures, GO terms, how many proteins, structures and taxa it covers, its Pfam clan, its
representative structure, its overlapping entries and its place in InterPro's hierarchy; a UniProt
protein (`P68871`) with the InterPro entries and Pfam families it matches as related things, by
their type (domain, family, homologous superfamily, repeat, site), and where on the sequence; a
search of InterPro entries by text. Through the InterPro API, no key.

```php
$interpro = (new InterProSourceFactory($http))->create(['mailto' => 'support@glitchr.io']);
$globin = $interpro->entity('IPR000971');
$globin->kind;                               // domain
$globin->related('member')->keys();          // pfam:PF00042
$globin->value('go_term');                   // heme binding (GO:0020037, molecular function)
$protein = $interpro->entity('uniprot:P68871');
$protein->related('domain')->keys();         // interpro:IPR000971, pfam:PF00042
$protein->related('family')->keys();         // interpro:IPR002337, interpro:IPR050056
$protein->value('domain');                   // Globin (IPR000971): 3-147
$interpro->search(new Query('globin', limit: 3));   // 47 entries, by accession
```

```yaml
omnilab:
    sources:
        interpro:
            factory: interpro
            options:
                mailto: 'support@glitchr.io'   # in the User-Agent
```

Entries are named in the core's `interpro` and `pfam` schemes. The `Collector` reaches InterPro
from any source that gives a UniProt accession (`$collector->collect('uniprot', 'P68871')`): the
protein's domains and families join it as related things.

## Terms of use

Free to use under the [EMBL-EBI's terms of use](https://www.ebi.ac.uk/about/terms-of-use); InterPro
states its licence at [interpro/about/license](https://www.ebi.ac.uk/interpro/about/license/). Cite
Blum M. et al., "InterPro: the protein sequence classification resource in 2025", Nucleic Acids
Res. 2025, [10.1093/nar/gkae1082](https://doi.org/10.1093/nar/gkae1082). No rate is published.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by EMBL-EBI (InterPro). It reaches their service on behalf of whoever runs it, and it does not
redistribute their data beyond the short excerpts recorded as test fixtures. Its MIT licence
covers this package's code only and grants no right to the service or to its data.

Whoever installs and runs this package is responsible for complying with the terms above, for
obtaining whatever they require (an account, a key, a licence or subscription, an attribution or
a citation, a rate limit to respect), and for any use made of what it returns. The authors
publish it free of charge, make no commercial use of it, and accept no liability for how it is
used (see [LICENSE](LICENSE)).

License: MIT.
