<?php
declare(strict_types=1);
/**
 *  ------------------------------------------------------------------------
 *  samlSSO
 *
 *  samlSSO was inspired by the initial work of Derrick Smith's
 *  PhpSaml. This project's intend is to address some structural issues
 *  caused by the gradual development of GLPI and the broad amount of
 *  wishes expressed by the community.
 *
 *  Copyright (C) 2024 by Chris Gralike
 *  ------------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of samlSSO plugin for GLPI.
 *
 * samlSSO plugin is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * samlSSO is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with samlSSO. If not, see <http://www.gnu.org/licenses/> or
 * https://choosealicense.com/licenses/gpl-3.0/
 *
 * ------------------------------------------------------------------------
 *
 *  @package    samlSSO
 *  @version    1.2.9
 *  @author     Chris Gralike
 *  @copyright  Copyright (c) 2024 by Chris Gralike
 *  @license    GPLv3+
 *  @see        https://github.com/DonutsNL/samlSSO/readme.md
 *  @link       https://github.com/DonutsNL/samlSSO
 *  @since      1.2.9
 * ------------------------------------------------------------------------
 *
 * Stores the per-IdP SCIM externalId ↔ GLPI user mapping.
 * Each row maps one (users_id, idps_id) pair to the externalId
 * that the provisioning Identity Provider assigned to that user.
 * Because the mapping is scoped per IdP, the same GLPI user can
 * be provisioned by multiple IdPs without conflict, and the same
 * externalId is only required to be unique within a single IdP.
 *
 **/

namespace GlpiPlugin\Samlsso;

use Session;
use Migration;
use CommonDBTM;
use DBConnection;

class ScimUser extends CommonDBTM
{
    // Column constants
    public const TABLE         = 'glpi_plugin_samlsso_scim_users';
    public const ID            = 'id';
    public const USERS_ID      = 'users_id';
    public const IDPS_ID       = 'idps_id';
    public const EXTERNAL_ID   = 'external_id';
    public const DATE_CREATION = 'date_creation';
    public const DATE_MOD      = 'date_mod';

    /**
     * Override table name — prevents GLPI's automatic name derivation from
     * producing an unexpected table name for CamelCase class names.
     */
    public static function getTable($classname = null): string
    {
        return self::TABLE;
    }

    // -------------------------------------------------------------------------
    // Static helper methods used by Scim.php
    // -------------------------------------------------------------------------

    /**
     * Find a GLPI users_id by the externalId the IdP assigned, scoped to
     * a single IdP configuration instance.
     *
     * @param int    $idpId       The plugin IdP config id (glpi_plugin_samlsso_configs.id).
     * @param string $externalId  The externalId as sent by the SCIM client.
     * @return int|null           GLPI users_id, or null if not found.
     */
    public static function findByExternalId(int $idpId, string $externalId): ?int
    {
        global $DB;

        $rows = $DB->request([
            'SELECT' => [self::USERS_ID],
            'FROM'   => self::TABLE,
            'WHERE'  => [
                self::IDPS_ID     => $idpId,
                self::EXTERNAL_ID => $externalId,
            ],
            'LIMIT'  => 1,
        ]);

        foreach ($rows as $row) {
            return (int) $row[self::USERS_ID];
        }

        return null;
    }

    /**
     * Return the externalId stored for a GLPI user on a specific IdP,
     * or null when no mapping exists yet.
     *
     * @param int $usersId  GLPI users_id.
     * @param int $idpId    The plugin IdP config id.
     * @return string|null
     */
    public static function getExternalId(int $usersId, int $idpId): ?string
    {
        global $DB;

        $rows = $DB->request([
            'SELECT' => [self::EXTERNAL_ID],
            'FROM'   => self::TABLE,
            'WHERE'  => [
                self::USERS_ID => $usersId,
                self::IDPS_ID  => $idpId,
            ],
            'LIMIT'  => 1,
        ]);

        foreach ($rows as $row) {
            return (string) $row[self::EXTERNAL_ID];
        }

        return null;
    }

    /**
     * Insert or update the mapping between a GLPI user and an IdP externalId.
     * If a row already exists for (users_id, idps_id), only external_id and
     * date_mod are updated; otherwise a new row is inserted.
     *
     * @param int    $usersId    GLPI users_id.
     * @param int    $idpId      The plugin IdP config id.
     * @param string $externalId The externalId as sent by the SCIM client.
     * @return void
     */
    public static function saveMapping(int $usersId, int $idpId, string $externalId): void
    {
        global $DB;

        $existing = $DB->request([
            'SELECT' => [self::ID],
            'FROM'   => self::TABLE,
            'WHERE'  => [
                self::USERS_ID => $usersId,
                self::IDPS_ID  => $idpId,
            ],
            'LIMIT'  => 1,
        ]);

        $now = date('Y-m-d H:i:s');

        if (count($existing) > 0) {
            foreach ($existing as $row) {
                $DB->update(self::TABLE, [
                    self::EXTERNAL_ID => $externalId,
                    self::DATE_MOD    => $now,
                ], [self::ID => $row[self::ID]]);
            }
        } else {
            $DB->insert(self::TABLE, [
                self::USERS_ID      => $usersId,
                self::IDPS_ID       => $idpId,
                self::EXTERNAL_ID   => $externalId,
                self::DATE_CREATION => $now,
                self::DATE_MOD      => $now,
            ]);
        }
    }

    /**
     * Remove the per-IdP mapping for a GLPI user.
     * Called when a SCIM DELETE request purges the user.
     *
     * @param int $usersId  GLPI users_id.
     * @param int $idpId    The plugin IdP config id.
     * @return void
     */
    public static function deleteMapping(int $usersId, int $idpId): void
    {
        global $DB;

        $DB->delete(self::TABLE, [
            self::USERS_ID => $usersId,
            self::IDPS_ID  => $idpId,
        ]);
    }

    /**
     * Check if a GLPI user has at least one SCIM mapping (for any IdP).
     * Used to detect SCIM/SAML-managed users and prevent admin UI overwrites.
     *
     * @param int $usersId  GLPI users_id.
     * @return bool
     */
    public static function isManagedUser(int $usersId): bool
    {
        global $DB;

        $rows = $DB->request([
            'SELECT' => [self::ID],
            'FROM'   => self::TABLE,
            'WHERE'  => [self::USERS_ID => $usersId],
            'LIMIT'  => 1,
        ]);

        return count($rows) > 0;
    }

    /**
     * Return all mapping rows for a given IdP, with optional pagination.
     * Used by SCIM GET /Users (list) to enumerate provisioned users.
     *
     * @param int $idpId  The plugin IdP config id.
     * @param int $start  0-based offset.
     * @param int $count  Maximum rows to return.
     * @return array
     */
    public static function getUsersByIdp(int $idpId, int $start = 0, int $count = 100): array
    {
        global $DB;

        $rows = $DB->request([
            'FROM'  => self::TABLE,
            'WHERE' => [self::IDPS_ID => $idpId],
            'ORDER' => [self::ID . ' ASC'],
            'START' => $start,
            'LIMIT' => $count,
        ]);

        return iterator_to_array($rows);
    }

    /**
     * Count total mapping rows for a given IdP.
     * Used to populate SCIM ListResponse.totalResults.
     *
     * @param int $idpId  The plugin IdP config id.
     * @return int
     */
    public static function countByIdp(int $idpId): int
    {
        global $DB;

        $rows = $DB->request([
            'COUNT' => 'cnt',
            'FROM'  => self::TABLE,
            'WHERE' => [self::IDPS_ID => $idpId],
        ]);

        foreach ($rows as $row) {
            return (int) $row['cnt'];
        }

        return 0;
    }

    // -------------------------------------------------------------------------
    // Installation / Uninstallation
    // -------------------------------------------------------------------------

    /**
     * Create the glpi_plugin_samlsso_scim_users table.
     * Auto-discovered by plugin_samlsso_install() in hook.php because this
     * file lives in the top-level /src/ directory.
     *
     * @param Migration $migration
     * @return void
     */
    public static function install(Migration $migration): void
    {
        global $DB;

        $default_charset   = DBConnection::getDefaultCharset();
        $default_collation = DBConnection::getDefaultCollation();
        $default_key_sign  = DBConnection::getDefaultPrimaryKeySignOption();

        $table = self::TABLE;

        if (!$DB->tableExists($table)) {
            $DB->doQuery("CREATE TABLE `$table` (
                `id`            int {$default_key_sign} NOT NULL AUTO_INCREMENT,
                `users_id`      int {$default_key_sign} NOT NULL,
                `idps_id`       int {$default_key_sign} NOT NULL,
                `external_id`   varchar(255) NOT NULL DEFAULT '',
                `date_creation` datetime NULL DEFAULT NULL,
                `date_mod`      datetime NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `idp_external_idx` (`idps_id`, `external_id`),
                KEY `users_id_idx` (`users_id`)
            ) ENGINE=InnoDB
              DEFAULT CHARSET={$default_charset}
              COLLATE={$default_collation}
              ROW_FORMAT=COMPRESSED");
            Session::addMessageAfterRedirect("🆗 Installed: $table.");
        }
    }

    /**
     * Drop the glpi_plugin_samlsso_scim_users table on plugin uninstall.
     *
     * @param Migration $migration
     * @return void
     */
    public static function uninstall(Migration $migration): void
    {
        $table = self::TABLE;
        $migration->dropTable($table);
        Session::addMessageAfterRedirect("🆗 Removed: $table.");
    }
}
