<?php
declare(strict_types=1);

namespace GlpiPlugin\Samlsso\Tests\Unit;

use DbTestCase;
use GlpiPlugin\Samlsso\ScimUser;

/**
 * Unit / integration tests for ScimUser.
 *
 * Each test runs inside a GLPI test-database transaction that is rolled back
 * after the test, so the mapping table stays clean between runs.
 *
 * Run with:
 *   ./vendor/bin/phpunit --testsuite Unit tests/Unit/ScimUserTest.php
 */
class ScimUserTest extends DbTestCase
{
    // Arbitrary but stable IDs that reference rows we insert ourselves.
    private const IDP_ID  = 9901;
    private const USER_ID = 9901;

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Directly insert a raw mapping row so individual method tests can
     * work without depending on saveMapping().
     */
    private function insertMapping(int $usersId, int $idpId, string $externalId): void
    {
        global $DB;
        $DB->insert(ScimUser::TABLE, [
            ScimUser::USERS_ID      => $usersId,
            ScimUser::IDPS_ID       => $idpId,
            ScimUser::EXTERNAL_ID   => $externalId,
            ScimUser::DATE_CREATION => date('Y-m-d H:i:s'),
            ScimUser::DATE_MOD      => date('Y-m-d H:i:s'),
        ]);
    }

    // -----------------------------------------------------------------------
    // saveMapping + findByExternalId
    // -----------------------------------------------------------------------

    public function testSaveAndFindByExternalId(): void
    {
        ScimUser::saveMapping(self::USER_ID, self::IDP_ID, 'ext-abc');

        $found = ScimUser::findByExternalId(self::IDP_ID, 'ext-abc');
        $this->assertSame(self::USER_ID, $found);
    }

    public function testFindByExternalIdReturnsNullWhenNotFound(): void
    {
        $result = ScimUser::findByExternalId(self::IDP_ID, 'no-such-ext-id');
        $this->assertNull($result);
    }

    public function testExternalIdIsScopedPerIdp(): void
    {
        ScimUser::saveMapping(self::USER_ID, self::IDP_ID, 'ext-shared');

        // Same externalId on a different IdP must NOT be found on original IdP.
        $differentIdp = self::IDP_ID + 1;
        $this->assertNull(ScimUser::findByExternalId($differentIdp, 'ext-shared'));
    }

    // -----------------------------------------------------------------------
    // saveMapping upsert behaviour
    // -----------------------------------------------------------------------

    public function testSaveMappingUpdatesExistingRow(): void
    {
        global $DB;

        ScimUser::saveMapping(self::USER_ID, self::IDP_ID, 'ext-v1');
        ScimUser::saveMapping(self::USER_ID, self::IDP_ID, 'ext-v2');

        // Only one row must exist for this (users_id, idps_id) pair.
        $rows = $DB->request([
            'COUNT' => 'cnt',
            'FROM'  => ScimUser::TABLE,
            'WHERE' => [
                ScimUser::USERS_ID => self::USER_ID,
                ScimUser::IDPS_ID  => self::IDP_ID,
            ],
        ]);
        foreach ($rows as $row) {
            $this->assertSame(1, (int) $row['cnt']);
        }

        // And the stored value should be the second one.
        $this->assertSame('ext-v2', ScimUser::getExternalId(self::USER_ID, self::IDP_ID));
    }

    // -----------------------------------------------------------------------
    // getExternalId
    // -----------------------------------------------------------------------

    public function testGetExternalIdReturnsStoredValue(): void
    {
        $this->insertMapping(self::USER_ID, self::IDP_ID, 'ext-xyz');

        $result = ScimUser::getExternalId(self::USER_ID, self::IDP_ID);
        $this->assertSame('ext-xyz', $result);
    }

    public function testGetExternalIdReturnsNullWhenAbsent(): void
    {
        $result = ScimUser::getExternalId(99999, 99999);
        $this->assertNull($result);
    }

    // -----------------------------------------------------------------------
    // deleteMapping
    // -----------------------------------------------------------------------

    public function testDeleteMappingRemovesRow(): void
    {
        $this->insertMapping(self::USER_ID, self::IDP_ID, 'ext-del');

        ScimUser::deleteMapping(self::USER_ID, self::IDP_ID);

        $this->assertNull(ScimUser::getExternalId(self::USER_ID, self::IDP_ID));
        $this->assertNull(ScimUser::findByExternalId(self::IDP_ID, 'ext-del'));
    }

    public function testDeleteMappingOnlyRemovesMatchingIdp(): void
    {
        $otherIdp = self::IDP_ID + 5;
        $this->insertMapping(self::USER_ID, self::IDP_ID, 'ext-keep');
        $this->insertMapping(self::USER_ID, $otherIdp, 'ext-other');

        ScimUser::deleteMapping(self::USER_ID, $otherIdp);

        // Original mapping must still be intact.
        $this->assertSame('ext-keep', ScimUser::getExternalId(self::USER_ID, self::IDP_ID));
    }

    // -----------------------------------------------------------------------
    // isManagedUser
    // -----------------------------------------------------------------------

    public function testIsManagedUserReturnsTrueWhenMappingExists(): void
    {
        $this->insertMapping(self::USER_ID, self::IDP_ID, 'ext-managed');
        $this->assertTrue(ScimUser::isManagedUser(self::USER_ID));
    }

    public function testIsManagedUserReturnsFalseWhenNoMapping(): void
    {
        $this->assertFalse(ScimUser::isManagedUser(88888));
    }

    // -----------------------------------------------------------------------
    // countByIdp + getUsersByIdp
    // -----------------------------------------------------------------------

    public function testCountByIdpReturnsCorrectTotal(): void
    {
        $idp = self::IDP_ID + 10;
        $this->insertMapping(1001, $idp, 'ext-u1');
        $this->insertMapping(1002, $idp, 'ext-u2');
        $this->insertMapping(1003, $idp, 'ext-u3');

        $this->assertSame(3, ScimUser::countByIdp($idp));
    }

    public function testCountByIdpReturnsZeroForUnknownIdp(): void
    {
        $this->assertSame(0, ScimUser::countByIdp(99998));
    }

    public function testGetUsersByIdpReturnsMappingsWithPagination(): void
    {
        $idp = self::IDP_ID + 20;
        for ($i = 1; $i <= 5; $i++) {
            $this->insertMapping(2000 + $i, $idp, "ext-p$i");
        }

        // Page 1: first 3 rows (0-based offset 0, count 3)
        $page1 = ScimUser::getUsersByIdp($idp, 0, 3);
        $this->assertCount(3, $page1);

        // Page 2: remaining 2 rows (offset 3, count 3)
        $page2 = ScimUser::getUsersByIdp($idp, 3, 3);
        $this->assertCount(2, $page2);
    }

    public function testGetUsersByIdpReturnsExternalIdColumn(): void
    {
        $idp = self::IDP_ID + 30;
        $this->insertMapping(3001, $idp, 'ext-col');

        $rows = ScimUser::getUsersByIdp($idp, 0, 10);
        $this->assertCount(1, $rows);
        $this->assertSame('ext-col', $rows[0][ScimUser::EXTERNAL_ID]);
        $this->assertSame(3001, (int) $rows[0][ScimUser::USERS_ID]);
    }
}
