<?php

namespace Omnilab\InterPro;

use Omnilab\Config;
use Omnilab\Source\SourceFactory;
use Omnilab\Source\SourceInterface;
use Symfony\Component\HttpClient\HttpClient;

/**
 * InterPro: InterPro entries and Pfam families, the domains and families a
 * UniProt protein matches, a search - no key needed.
 *
 *   options:
 *     mailto: 'support@glitchr.io'     # in the User-Agent
 *     throttle: 0                      # seconds between two calls: InterPro publishes no rate
 *     base_uri: 'https://www.ebi.ac.uk/interpro/api/'
 */
final class InterProSourceFactory extends SourceFactory
{
    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnilab.factory_name' => 'interpro',
            'omnilab.required_options' => [],
            'throttle' => 0.0,
            'base_uri' => InterProSource::BASE_URI,
        ]);
    }

    protected function build(Config $c): SourceInterface
    {
        return new InterProSource(
            $this->http ?? HttpClient::create(),
            (string) $c->get('base_uri', InterProSource::BASE_URI),
            ['User-Agent' => self::userAgent($c)],
            (float) $c->get('throttle'),
        );
    }
}
