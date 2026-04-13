<?php
declare(strict_types=1);

namespace GlpiPlugin\Samlsso\Tests\Unit;

use DbTestCase;
use ReflectionMethod;
use GlpiPlugin\Samlsso\LoginFlow\Scim;
use GlpiPlugin\Samlsso\ScimUser;
use GlpiPlugin\Samlsso\Config\ConfigEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests for the Scim request handler.
 *
 * Strategy:
 * - Private method tests use ReflectionMethod + stub ConfigEntity so they
 *   are isolated from GLPI's config-loading infrastructure.
 * - CRUD integration tests (createUser, updateUser, deleteUser, getUser)
 *   use a real GLPI test database via DbTestCase and direct ReflectionMethod
 *   invocation so a fully valid SAML ConfigEntity row is not required.
 *
 * Run:
 *   ./vendor/bin/phpunit --testsuite Unit tests/Unit/ScimTest.php
 */
class ScimTest extends DbTestCase
{
    private const VALID_TOKEN = 'secure-test-bearer-token';
    private const IDP_ID      = 9501;

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Build a stub ConfigEntity that returns predictable values for getField().
     *
     * @param bool        $scimActive
     * @param string      $token
     * @param int         $idpId
     * @return ConfigEntity (stub)
     */
    private function makeConfigStub(
        bool $scimActive = true,
        string $token = self::VALID_TOKEN,
        int $idpId = self::IDP_ID
    ): ConfigEntity {
        /** @var ConfigEntity $stub */
        $stub = $this->createStub(ConfigEntity::class);
        $stub->method('isValid')->willReturn(true);
        $stub->method('getField')->willReturnMap([
            [ConfigEntity::SCIM_ACTIVE, $scimActive],
            [ConfigEntity::SCIM_TOKEN,  $token],
            [ConfigEntity::ID,          $idpId],
        ]);
        return $stub;
    }

    /**
     * Build a Symfony Request with the path, method, body and headers set.
     */
    private function makeRequest(
        string $method,
        string $path,
        array $query = [],
        string $body = '',
        string $authToken = self::VALID_TOKEN
    ): Request {
        $request = Request::create(
            $path,
            $method,
            $query,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            $body
        );
        if ($authToken !== '') {
            $request->headers->set('Authorization', 'Bearer ' . $authToken);
        } else {
            $request->headers->remove('Authorization');
        }
        return $request;
    }

    /**
     * Returns a callable ReflectionMethod for a private method on Scim.
     */
    private function reflectScimMethod(string $methodName): ReflectionMethod
    {
        $method = new ReflectionMethod(Scim::class, $methodName);
        $method->setAccessible(true);
        return $method;
    }

    /**
     * Insert a GLPI test user and return its id.
     */
    private function insertGlpiUser(string $name = 'scim_test_user'): int
    {
        $user = new \User();
        $id = $user->add([
            'name'      => $name,
            'firstname' => 'SCIM',
            'realname'  => 'Test',
            'is_active' => 1,
            'authtype'  => 4,
        ]);
        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);
        return (int) $id;
    }

    // -----------------------------------------------------------------------
    // authenticate() — private method unit tests
    // -----------------------------------------------------------------------

    public function testAuthenticateAcceptsValidToken(): void
    {
        $auth = $this->reflectScimMethod('authenticate');
        $scim = new Scim();

        $request = $this->makeRequest('GET', '/Users');
        $config  = $this->makeConfigStub();

        $this->assertTrue($auth->invoke($scim, $request, $config));
    }

    public function testAuthenticateRejectsWrongToken(): void
    {
        $auth = $this->reflectScimMethod('authenticate');
        $scim = new Scim();

        $request = $this->makeRequest('GET', '/Users', [], '', 'wrong-token');
        $config  = $this->makeConfigStub();

        $this->assertFalse($auth->invoke($scim, $request, $config));
    }

    public function testAuthenticateRejectsMissingHeader(): void
    {
        $auth = $this->reflectScimMethod('authenticate');
        $scim = new Scim();

        $request = $this->makeRequest('GET', '/Users', [], '', '');
        $config  = $this->makeConfigStub();

        $this->assertFalse($auth->invoke($scim, $request, $config));
    }

    public function testAuthenticateRejectsBearerPrefixMissing(): void
    {
        $auth = $this->reflectScimMethod('authenticate');
        $scim = new Scim();

        $request = Request::create('/Users', 'GET');
        $request->headers->set('Authorization', self::VALID_TOKEN); // no "Bearer " prefix
        $config  = $this->makeConfigStub();

        $this->assertFalse($auth->invoke($scim, $request, $config));
    }

    /**
     * @group regression
     *
     * When scim_token is empty AND the client sends "Authorization: Bearer ",
     * the current implementation passes authentication (empty === empty).
     * This test documents the vulnerability — it is expected to FAIL until the
     * bug is fixed by adding a non-empty guard in ConfigItem or Scim::authenticate().
     */
    public function testAuthEmptyTokenShouldRejectEmptyBearer(): void
    {
        $auth = $this->reflectScimMethod('authenticate');
        $scim = new Scim();

        $request = Request::create('/Users', 'GET');
        $request->headers->set('Authorization', 'Bearer '); // empty token part
        $config  = $this->makeConfigStub(token: '');        // empty stored token

        // This SHOULD be false — document that the current implementation
        // incorrectly returns true when both tokens are empty strings.
        $this->assertFalse(
            $auth->invoke($scim, $request, $config),
            'Empty bearer token must not authenticate even when config token is also empty.'
        );
    }

    // -----------------------------------------------------------------------
    // handleRequest() routing → unimplemented endpoints
    // -----------------------------------------------------------------------

    public function testUnknownEndpointReturns501(): void
    {
        $handle  = $this->reflectScimMethod('handleRequest');
        $scim    = new Scim();
        $config  = $this->makeConfigStub();

        $request = $this->makeRequest('GET', '/Groups');
        /** @var \Symfony\Component\HttpFoundation\JsonResponse $response */
        $response = $handle->invoke($scim, $request, $config);

        $this->assertSame(Response::HTTP_NOT_IMPLEMENTED, $response->getStatusCode());
    }

    // -----------------------------------------------------------------------
    // mapScimToUser() — field mapping
    // -----------------------------------------------------------------------

    public function testMapScimToUserMapsExpectedFields(): void
    {
        $map  = $this->reflectScimMethod('mapScimToUser');
        $scim = new Scim();

        $data = [
            'userName' => 'john.doe',
            'name'     => ['familyName' => 'Doe', 'givenName' => 'John'],
            'emails'   => [['value' => 'john@example.com']],
            'active'   => true,
        ];

        $result = $map->invoke($scim, $data);

        $this->assertSame('john.doe',        $result['name']);
        $this->assertSame('Doe',             $result['realname']);
        $this->assertSame('John',            $result['firstname']);
        $this->assertSame(1,                 $result['is_active']);
        $this->assertSame(['john@example.com'], $result['_useremails']);
        $this->assertSame(4,                 $result['authtype']);
    }

    public function testMapScimToUserHandlesMissingOptionalFields(): void
    {
        $map  = $this->reflectScimMethod('mapScimToUser');
        $scim = new Scim();

        $result = $map->invoke($scim, []); // empty payload

        $this->assertSame('', $result['name']);
        $this->assertSame('', $result['realname']);
        $this->assertSame('', $result['firstname']);
        $this->assertSame([], $result['_useremails']);
        $this->assertSame(1,  $result['is_active']); // defaults to active
    }

    // -----------------------------------------------------------------------
    // mapUserToScim() — field mapping and email bug regression
    // -----------------------------------------------------------------------

    public function testMapUserToScimIncludesExternalId(): void
    {
        $map  = $this->reflectScimMethod('mapUserToScim');
        $scim = new Scim();

        $user = new \User();
        $user->fields = [
            'id'        => 42,
            'name'      => 'jane.doe',
            'realname'  => 'Doe',
            'firstname' => 'Jane',
            'is_active' => 1,
        ];

        $result = $map->invoke($scim, $user, 'ext-id-99');

        $this->assertSame('42',      $result['id']);
        $this->assertSame('jane.doe',$result['userName']);
        $this->assertSame('ext-id-99', $result['externalId']);
        $this->assertTrue($result['active']);
        $this->assertSame('urn:ietf:params:scim:schemas:core:2.0:User', $result['schemas'][0]);
    }

    public function testMapUserToScimOmitsExternalIdWhenNull(): void
    {
        $map  = $this->reflectScimMethod('mapUserToScim');
        $scim = new Scim();

        $user = new \User();
        $user->fields = ['id' => 1, 'name' => 'u', 'realname' => '', 'firstname' => '', 'is_active' => 1];

        $result = $map->invoke($scim, $user, null);

        $this->assertArrayNotHasKey('externalId', $result);
    }

    /**
     * @group regression
     *
     * Current implementation sets emails[0].value = user->fields['name'] (username)
     * instead of the actual email address stored on the GLPI user.
     * This test documents the bug.
     */
    public function testEmailFieldShouldNotBeUsername(): void
    {
        $map  = $this->reflectScimMethod('mapUserToScim');
        $scim = new Scim();

        $user = new \User();
        $user->fields = [
            'id'        => 5,
            'name'      => 'john.doe',          // username
            'realname'  => 'Doe',
            'firstname' => 'John',
            'is_active' => 1,
            // real email not included in fields mapping — bug: 'name' used as email
        ];

        $result = $map->invoke($scim, $user, null);

        // Document the current (wrong) behaviour: email equals the username.
        // Once the bug is fixed, this assertion should be replaced with a check
        // that emails[0].value equals the actual email field.
        $this->assertSame(
            $user->fields['name'],
            $result['emails'][0]['value'],
            'BUG: emails[0].value is mapped from username instead of the real email field.'
        );
    }

    // -----------------------------------------------------------------------
    // createUser() — integration via reflection + real DB
    // -----------------------------------------------------------------------

    public function testCreateUserReturns201AndStoresMapping(): void
    {
        $create = $this->reflectScimMethod('createUser');
        $scim   = new Scim();
        $config = $this->makeConfigStub();

        $payload = json_encode([
            'userName'   => 'scim.newuser.' . uniqid(),
            'externalId' => 'ext-new-' . uniqid(),
            'name'       => ['familyName' => 'New', 'givenName' => 'User'],
            'emails'     => [],
            'active'     => true,
        ]);

        $request = $this->makeRequest('POST', '/Users', [], $payload);
        /** @var \Symfony\Component\HttpFoundation\JsonResponse $response */
        $response = $create->invoke($scim, $request, $config);

        $this->assertSame(Response::HTTP_CREATED, $response->getStatusCode());

        $body = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('id',       $body);
        $this->assertArrayHasKey('userName', $body);
        $this->assertArrayHasKey('externalId', $body);

        // Verify mapping row was written
        $glpiId = (int) $body['id'];
        $this->assertSame(
            $body['externalId'],
            ScimUser::getExternalId($glpiId, self::IDP_ID)
        );
    }

    public function testCreateUserReturns409OnDuplicateExternalId(): void
    {
        $create = $this->reflectScimMethod('createUser');
        $scim   = new Scim();
        $config = $this->makeConfigStub();

        $extId   = 'ext-dup-' . uniqid();
        $userId  = $this->insertGlpiUser('scim.dup.' . uniqid());

        ScimUser::saveMapping($userId, self::IDP_ID, $extId);

        $payload = json_encode([
            'userName'   => 'scim.conflict.' . uniqid(),
            'externalId' => $extId,
            'name'       => ['familyName' => 'X', 'givenName' => 'Y'],
        ]);

        $request  = $this->makeRequest('POST', '/Users', [], $payload);
        $response = $create->invoke($scim, $request, $config);

        $this->assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
    }

    public function testCreateUserReturns409OnDuplicateUsername(): void
    {
        $create   = $this->reflectScimMethod('createUser');
        $scim     = new Scim();
        $config   = $this->makeConfigStub();
        $userName = 'scim.existing.' . uniqid();

        $this->insertGlpiUser($userName);

        $payload = json_encode(['userName' => $userName]);
        $request = $this->makeRequest('POST', '/Users', [], $payload);

        $response = $create->invoke($scim, $request, $config);
        $this->assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
    }

    public function testCreateUserReturns400ForInvalidJson(): void
    {
        $create  = $this->reflectScimMethod('createUser');
        $scim    = new Scim();
        $config  = $this->makeConfigStub();
        $request = $this->makeRequest('POST', '/Users', [], 'not-json');

        $response = $create->invoke($scim, $request, $config);
        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    // -----------------------------------------------------------------------
    // getUser() — integration via reflection + real DB
    // -----------------------------------------------------------------------

    public function testGetUserReturns200WithExternalId(): void
    {
        $getUser = $this->reflectScimMethod('getUser');
        $scim    = new Scim();
        $config  = $this->makeConfigStub();

        $userId = $this->insertGlpiUser('scim.getuser.' . uniqid());
        ScimUser::saveMapping($userId, self::IDP_ID, 'ext-get-' . $userId);

        $request  = $this->makeRequest('GET', '/Users/' . $userId);
        $response = $getUser->invoke($scim, (string) $userId, $config);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $body = json_decode($response->getContent(), true);
        $this->assertSame((string) $userId, $body['id']);
        $this->assertArrayHasKey('externalId', $body);
    }

    public function testGetUserReturns404ForUnknownId(): void
    {
        $getUser  = $this->reflectScimMethod('getUser');
        $scim     = new Scim();
        $config   = $this->makeConfigStub();
        $response = $getUser->invoke($scim, '999999999', $config);

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    // -----------------------------------------------------------------------
    // updateUser() — integration via reflection + real DB
    // -----------------------------------------------------------------------

    public function testUpdateUserReturns200AndPersistsChanges(): void
    {
        $update = $this->reflectScimMethod('updateUser');
        $scim   = new Scim();
        $config = $this->makeConfigStub();

        $userId = $this->insertGlpiUser('scim.update.' . uniqid());

        $payload = json_encode([
            'userName'   => 'scim.updated.' . uniqid(),
            'externalId' => 'ext-upd-' . $userId,
            'name'       => ['familyName' => 'Updated', 'givenName' => 'Name'],
            'active'     => true,
        ]);

        $request  = $this->makeRequest('PUT', '/Users/' . $userId, [], $payload);
        $response = $update->invoke($scim, $request, (string) $userId, $config);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());

        $body = json_decode($response->getContent(), true);
        $this->assertSame('Updated', $body['name']['familyName']);
    }

    public function testUpdateUserReturns404ForUnknownId(): void
    {
        $update   = $this->reflectScimMethod('updateUser');
        $scim     = new Scim();
        $config   = $this->makeConfigStub();
        $request  = $this->makeRequest('PUT', '/Users/999999999', [], '{"userName":"x"}');
        $response = $update->invoke($scim, $request, '999999999', $config);

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testUpdateUserReturns400ForInvalidJson(): void
    {
        $update   = $this->reflectScimMethod('updateUser');
        $scim     = new Scim();
        $config   = $this->makeConfigStub();
        $userId   = $this->insertGlpiUser('scim.updjson.' . uniqid());
        $request  = $this->makeRequest('PUT', '/Users/' . $userId, [], 'not-json');
        $response = $update->invoke($scim, $request, (string) $userId, $config);

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    /**
     * @group regression
     *
     * PATCH is currently treated identically to PUT (full replace).
     * SCIM RFC 7644 §3.5.2 requires PATCH to process a PatchOp document
     * (add/remove/replace operations), NOT a full resource replacement.
     * This test documents the current behaviour so any future fix is visible.
     */
    public function testPatchIsTreatedAsFullReplaceNotPatchOp(): void
    {
        $handle = $this->reflectScimMethod('handleRequest');
        $scim   = new Scim();
        $config = $this->makeConfigStub();

        $userId  = $this->insertGlpiUser('scim.patch.' . uniqid());

        // A proper PatchOp payload (RFC 7644 §3.5.2)
        $patchOp = json_encode([
            'schemas'    => ['urn:ietf:params:scim:api:messages:2.0:PatchOp'],
            'Operations' => [
                ['op' => 'replace', 'path' => 'active', 'value' => false],
            ],
        ]);

        $request = $this->makeRequest('PATCH', '/Users/' . $userId, [], $patchOp);
        // The route regex in handleRequest() passes $userId from URL match;
        // simulate by setting it on attributes.
        $request->attributes->set('userId', (string) $userId);

        $response = $handle->invoke($scim, $request, $config);

        // Current implementation routes PATCH to updateUser() which decodes the
        // PatchOp body as a user resource — it will not find 'userName' etc.
        // Documenting current (incorrect) status code and not enforcing correctness.
        $this->assertNotSame(
            Response::HTTP_NOT_IMPLEMENTED,
            $response->getStatusCode(),
            'PATCH should be routed (even if incorrectly handled); 501 means routing is broken.'
        );
    }

    // -----------------------------------------------------------------------
    // deleteUser() — integration via reflection + real DB
    // -----------------------------------------------------------------------

    public function testDeleteUserReturns204AndRemovesMapping(): void
    {
        $delete = $this->reflectScimMethod('deleteUser');
        $scim   = new Scim();
        $config = $this->makeConfigStub();

        $userId = $this->insertGlpiUser('scim.delete.' . uniqid());
        ScimUser::saveMapping($userId, self::IDP_ID, 'ext-del-' . $userId);

        $response = $delete->invoke($scim, (string) $userId, $config);

        $this->assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
        $this->assertNull(ScimUser::getExternalId($userId, self::IDP_ID));
    }

    public function testDeleteUserReturns404ForUnknownId(): void
    {
        $delete   = $this->reflectScimMethod('deleteUser');
        $scim     = new Scim();
        $config   = $this->makeConfigStub();
        $response = $delete->invoke($scim, '999999999', $config);

        $this->assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    // -----------------------------------------------------------------------
    // getUsers() — list + pagination
    // -----------------------------------------------------------------------

    public function testGetUsersListResponseStructure(): void
    {
        $getUsers = $this->reflectScimMethod('getUsers');
        $scim     = new Scim();
        $config   = $this->makeConfigStub();

        $request  = $this->makeRequest('GET', '/Users', ['startIndex' => 1, 'count' => 10]);
        $response = $getUsers->invoke($scim, $request, $config);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());

        $body = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('schemas',      $body);
        $this->assertArrayHasKey('totalResults', $body);
        $this->assertArrayHasKey('startIndex',   $body);
        $this->assertArrayHasKey('itemsPerPage', $body);
        $this->assertArrayHasKey('Resources',    $body);
        $this->assertSame(
            'urn:ietf:params:scim:api:messages:2.0:ListResponse',
            $body['schemas'][0]
        );
    }

    public function testGetUsersStartIndexIsOneBasedAndConvertsCorrectly(): void
    {
        $getUsers = $this->reflectScimMethod('getUsers');
        $scim     = new Scim();
        $config   = $this->makeConfigStub();

        // startIndex=1 is the RFC default and must not be converted to negative offset
        $request  = $this->makeRequest('GET', '/Users', ['startIndex' => 1, 'count' => 5]);
        $response = $getUsers->invoke($scim, $request, $config);

        $body = json_decode($response->getContent(), true);
        $this->assertSame(1, $body['startIndex']);
        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    }
}
