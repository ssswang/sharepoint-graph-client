<?php

declare(strict_types=1);

namespace SharepointGraphClient\Tests;

use SharepointGraphClient\GraphException;
use SharepointGraphClient\GraphList;

class GraphRequestBehaviourTest extends MockHttpTestCase
{
    public function test_token_acquisition_sends_client_credentials_grant(): void
    {
        $site = $this->makeSite();

        $this->queue($this->jsonResponse(200, [
            'token_type'   => 'Bearer',
            'expires_in'   => 3599,
            'access_token' => 'TOKEN1',
        ]));

        $site->createGraphAccessToken();

        $this->assertSame('POST', $this->requestMethod(0));
        $this->assertSame('https://login.microsoftonline.com/11111111-2222-3333-4444-555555555555/oauth2/v2.0/token', $this->requestUri(0));

        $form = $this->requestForm(0);

        $this->assertSame('client_credentials', $form['grant_type']);
        $this->assertSame('client-id', $form['client_id']);
        $this->assertSame('https://graph.microsoft.com/.default', $form['scope']);
        $this->assertSame('shhh', $form['client_secret']);

        // no Access Token is sent to acquire an Access Token
        $this->assertSame('', $this->requestHeader(0, 'Authorization'));
    }

    public function test_requests_carry_the_access_token_and_resolve_paths(): void
    {
        $site = $this->makeSite();

        $this->queue(
            $this->jsonResponse(200, [
                'token_type'   => 'Bearer',
                'expires_in'   => 3599,
                'access_token' => 'TOKEN1',
            ]),
            $this->jsonResponse(200, ['id' => 'SITE-ID', 'webUrl' => 'https://contoso.sharepoint.com/sites/team']),
        );

        $this->assertSame('SITE-ID', $site->getSiteId());

        $this->assertSame('GET', $this->requestMethod(1));
        $this->assertSame('/v1.0/sites/contoso.sharepoint.com:/sites/team', $this->requestPath(1));
        $this->assertSame('Bearer TOKEN1', $this->requestHeader(1, 'Authorization'));
        $this->assertSame('application/json', $this->requestHeader(1, 'Accept'));
        $this->assertSame('id,webUrl,displayName,name', $this->requestQuery(1)['$select']);
    }

    public function test_throttled_requests_are_retried_with_retry_after(): void
    {
        $site = $this->makeSiteWithToken();

        $this->queue(
            $this->jsonResponse(429, ['error' => ['code' => 'throttled', 'message' => 'slow down']], ['Retry-After' => '1']),
            $this->jsonResponse(200, ['value' => [['id' => 'L1', 'displayName' => 'Docs', 'list' => ['template' => 'documentLibrary']]]]),
        );

        $lists = GraphList::getAll($site);

        $this->assertCount(1, $lists);
        $this->assertSame('Docs', $lists['L1']->getTitle());
        $this->assertTrue($lists['L1']->isWritable());
        $this->assertSame(2, $this->requestCount());
    }

    public function test_unauthorized_response_triggers_token_refresh_and_retry(): void
    {
        $site = $this->makeSiteWithToken();

        $this->queue(
            $this->jsonResponse(401, ['error' => ['code' => 'InvalidAuthenticationToken', 'message' => 'expired']]),
            $this->jsonResponse(200, ['token_type' => 'Bearer', 'expires_in' => 3599, 'access_token' => 'TOKEN2']),
            $this->jsonResponse(200, ['id' => 'L2', 'displayName' => 'Docs2', 'list' => ['template' => 'genericList']]),
        );

        $list = GraphList::getByGUID($site, '00000000-0000-0000-0000-00000000000e');

        $this->assertSame('L2', $list->getId());
        $this->assertSame('TOKEN2', (string) $site->getGraphAccessToken());
        $this->assertSame(3, $this->requestCount());
    }

    public function test_graph_error_responses_are_parsed(): void
    {
        $site = $this->makeSiteWithToken();

        $this->queue($this->jsonResponse(404, ['error' => ['code' => 'itemNotFound', 'message' => 'The item was not found']]));

        try {
            $site->request('sites/x');
            $this->fail('GraphException expected');
        } catch (GraphException $e) {
            $this->assertSame(404, $e->getCode());
            $this->assertSame('itemNotFound: The item was not found', $e->getMessage());
        }
    }

    public function test_collection_pagination_follows_next_links(): void
    {
        $site = $this->makeSiteWithToken();

        $this->queue(
            $this->jsonResponse(200, ['id' => 'L2', 'displayName' => 'Docs2', 'list' => ['template' => 'genericList']]),
            $this->jsonResponse(200, [
                'value'           => [['id' => 'i1', 'fields' => ['Title' => 'A']]],
                '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/sites/SITE-ID/lists/L2/items?$skiptoken=abc',
            ]),
            $this->jsonResponse(200, ['value' => [['id' => 'i2', 'fields' => ['Title' => 'B']]]]),
        );

        $list = GraphList::getByGUID($site, '00000000-0000-0000-0000-00000000000e');
        $items = $list->getGraphItems();

        $this->assertCount(2, $items);
        $this->assertSame('A', $items['i1']->getField('Title'));
        $this->assertSame('B', $items['i2']->getField('Title'));

        // the second page request follows the next link, and the stale
        // query options of the first page are not repeated
        $this->assertSame('GET', $this->requestMethod(2));
        $this->assertSame('/v1.0/sites/SITE-ID/lists/L2/items', $this->requestPath(2));

        $query = $this->requestQuery(2);

        $this->assertSame('abc', $query['$skiptoken']);
        $this->assertArrayNotHasKey('$expand', $query);
        $this->assertArrayNotHasKey('$top', $query);
    }
}
