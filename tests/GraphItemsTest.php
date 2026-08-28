<?php

declare(strict_types=1);

namespace SharepointGraphClient\Tests;

use GuzzleHttp\Psr7\Response;

use SharepointGraphClient\GraphItem;
use SharepointGraphClient\GraphList;
use SharepointGraphClient\GraphSite;
use SharepointGraphClient\GraphUser;

class GraphItemsTest extends MockHttpTestCase
{
    protected function makeList(GraphSite $site): GraphList
    {
        return new GraphList($site, [
            'id'          => 'L1',
            'displayName' => 'Docs',
            'name'        => 'Docs',
            'list'        => ['template' => 'documentLibrary'],
        ]);
    }

    public function test_item_create_sends_fields_wrapper(): void
    {
        $site = $this->makeSiteWithToken();
        $list = $this->makeList($site);

        $this->queue($this->jsonResponse(201, ['id' => 'i3', 'fields' => ['Title' => 'C']]));

        $item = $list->createGraphItem(['Title' => 'C']);

        $this->assertSame('i3', $item->getId());
        $this->assertSame('C', $item->getTitle());

        $this->assertSame('POST', $this->requestMethod(0));
        $this->assertSame('/v1.0/sites/SITE-ID/lists/L1/items', $this->requestPath(0));
        $this->assertSame(['fields' => ['Title' => 'C']], $this->requestJson(0));
    }

    public function test_item_update_sends_if_match_and_rehydrates(): void
    {
        $site = $this->makeSiteWithToken();
        $list = $this->makeList($site);
        $item = new GraphItem($list, ['id' => 'i3', 'fields' => ['Title' => 'C']]);

        $this->queue($this->jsonResponse(200, ['id' => 'i3', 'fields' => ['Title' => 'C2', 'Status' => 'Done']]));

        $item->update(['Title' => 'C2']);

        $this->assertSame('PATCH', $this->requestMethod(0));
        $this->assertSame('/v1.0/sites/SITE-ID/lists/L1/items/i3', $this->requestPath(0));
        $this->assertSame(['fields' => ['Title' => 'C2']], $this->requestJson(0));
        $this->assertSame('*', $this->requestHeader(0, 'If-Match'));
        $this->assertSame('Done', $item->getField('Status'));
    }

    public function test_item_delete_sends_delete_request(): void
    {
        $site = $this->makeSiteWithToken();
        $list = $this->makeList($site);
        $item = new GraphItem($list, ['id' => 'i3', 'fields' => ['Title' => 'C']]);

        $this->queue(new Response(204));

        $this->assertTrue($item->delete());

        $this->assertSame('DELETE', $this->requestMethod(0));
        $this->assertSame('/v1.0/sites/SITE-ID/lists/L1/items/i3', $this->requestPath(0));
    }

    public function test_item_lookup_by_title_escapes_odata_filter(): void
    {
        $site = $this->makeSiteWithToken();
        $list = $this->makeList($site);

        $this->queue($this->jsonResponse(200, ['value' => [['id' => 'i9', 'fields' => ['Title' => "O'Brien"]]]]));

        $item = GraphItem::getByTitle($list, "O'Brien");

        $this->assertSame('i9', $item->getId());

        $query = $this->requestQuery(0);

        $this->assertSame("fields/Title eq 'O''Brien'", $query['$filter']);
        $this->assertSame('fields', $query['$expand']);
        $this->assertSame('1', $query['$top']);
    }

    public function test_item_lookup_by_arbitrary_field(): void
    {
        $site = $this->makeSiteWithToken();
        $list = $this->makeList($site);

        $this->queue($this->jsonResponse(200, ['value' => [['id' => 'i4', 'fields' => ['FileLeafRef' => 'report.docx']]]]));

        $item = GraphItem::getByField($list, 'FileLeafRef', 'report.docx');

        $this->assertSame('i4', $item->getId());
        $this->assertSame('report.docx', $item->getField('FileLeafRef'));

        $query = $this->requestQuery(0);

        $this->assertSame("fields/FileLeafRef eq 'report.docx'", $query['$filter']);
    }

    public function test_item_lookup_returns_null_when_not_found(): void
    {
        $site = $this->makeSiteWithToken();
        $list = $this->makeList($site);

        $this->queue($this->jsonResponse(200, ['value' => []]));

        $this->assertNull(GraphItem::getByTitle($list, 'missing'));
    }

    public function test_user_lookup_by_account(): void
    {
        $site = $this->makeSiteWithToken();

        $this->queue($this->jsonResponse(200, [
            'id'                => 'U1',
            'userPrincipalName' => 'song@contoso.com',
            'displayName'       => 'Song Wang',
            'givenName'         => 'Song',
            'surname'           => 'Wang',
            'mail'              => 'song@contoso.com',
            'jobTitle'          => 'Dev',
        ]));

        $user = GraphUser::getByAccount($site, 'song@contoso.com');

        $this->assertSame('U1', $user->getId());
        $this->assertSame('song@contoso.com', $user->getAccount());
        $this->assertSame('song@contoso.com', $user->getEmail());
        $this->assertSame('Song Wang', $user->getFullName());
        $this->assertSame('Dev', $user->getTitle());

        $this->assertSame('GET', $this->requestMethod(0));
        $this->assertSame('https://graph.microsoft.com/v1.0/users/song%40contoso.com', $this->requestUri(0));
    }
}
