<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crea el esquema inicial portable de identidad, organizaciones, eventos, permisos, invitaciones y auditoría.';
    }

    public function up(Schema $schema): void
    {
        $users = $schema->createTable('users');
        $users->addColumn('id', Types::STRING, ['length' => 36]);
        $users->addColumn('email', Types::STRING, ['length' => 254]);
        $users->addColumn('email_normalized', Types::STRING, ['length' => 254]);
        $users->addColumn('display_name', Types::STRING, ['length' => 160]);
        $users->addColumn('status', Types::STRING, ['length' => 32]);
        $users->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $users->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $users->setPrimaryKey(['id']);
        $users->addUniqueIndex(['email_normalized'], 'uniq_users_email_normalized');
        $users->addIndex(['status'], 'idx_users_status');

        $organizations = $schema->createTable('organizations');
        $organizations->addColumn('id', Types::STRING, ['length' => 36]);
        $organizations->addColumn('name', Types::STRING, ['length' => 160]);
        $organizations->addColumn('slug', Types::STRING, ['length' => 120]);
        $organizations->addColumn('status', Types::STRING, ['length' => 32]);
        $organizations->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $organizations->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $organizations->setPrimaryKey(['id']);
        $organizations->addUniqueIndex(['slug'], 'uniq_organizations_slug');
        $organizations->addIndex(['status'], 'idx_organizations_status');

        $roles = $schema->createTable('roles');
        $roles->addColumn('id', Types::STRING, ['length' => 36]);
        $roles->addColumn('code', Types::STRING, ['length' => 64]);
        $roles->addColumn('name', Types::STRING, ['length' => 120]);
        $roles->addColumn('scope', Types::STRING, ['length' => 20]);
        $roles->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $roles->setPrimaryKey(['id']);
        $roles->addUniqueIndex(['code'], 'uniq_roles_code');
        $roles->addIndex(['scope'], 'idx_roles_scope');

        $permissions = $schema->createTable('permissions');
        $permissions->addColumn('id', Types::STRING, ['length' => 36]);
        $permissions->addColumn('code', Types::STRING, ['length' => 64]);
        $permissions->addColumn('description', Types::STRING, ['length' => 255]);
        $permissions->setPrimaryKey(['id']);
        $permissions->addUniqueIndex(['code'], 'uniq_permissions_code');

        $rolePermissions = $schema->createTable('role_permissions');
        $rolePermissions->addColumn('role_id', Types::STRING, ['length' => 36]);
        $rolePermissions->addColumn('permission_id', Types::STRING, ['length' => 36]);
        $rolePermissions->setPrimaryKey(['role_id', 'permission_id']);
        $rolePermissions->addForeignKeyConstraint('roles', ['role_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_role_perm_role');
        $rolePermissions->addForeignKeyConstraint('permissions', ['permission_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_role_perm_permission');

        $organizationMemberships = $schema->createTable('organization_memberships');
        $organizationMemberships->addColumn('id', Types::STRING, ['length' => 36]);
        $organizationMemberships->addColumn('organization_id', Types::STRING, ['length' => 36]);
        $organizationMemberships->addColumn('user_id', Types::STRING, ['length' => 36]);
        $organizationMemberships->addColumn('status', Types::STRING, ['length' => 32]);
        $organizationMemberships->addColumn('valid_from', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $organizationMemberships->addColumn('valid_until', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $organizationMemberships->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $organizationMemberships->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $organizationMemberships->setPrimaryKey(['id']);
        $organizationMemberships->addUniqueIndex(['organization_id', 'user_id'], 'uniq_org_membership');
        $organizationMemberships->addIndex(['user_id', 'status'], 'idx_org_membership_user_status');
        $organizationMemberships->addForeignKeyConstraint('organizations', ['organization_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_org_membership_org');
        $organizationMemberships->addForeignKeyConstraint('users', ['user_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_org_membership_user');

        $organizationMembershipRoles = $schema->createTable('organization_membership_roles');
        $organizationMembershipRoles->addColumn('organization_membership_id', Types::STRING, ['length' => 36]);
        $organizationMembershipRoles->addColumn('role_id', Types::STRING, ['length' => 36]);
        $organizationMembershipRoles->addColumn('granted_by', Types::STRING, ['length' => 36, 'notnull' => false]);
        $organizationMembershipRoles->addColumn('granted_at', Types::DATETIME_IMMUTABLE);
        $organizationMembershipRoles->setPrimaryKey(['organization_membership_id', 'role_id']);
        $organizationMembershipRoles->addForeignKeyConstraint('organization_memberships', ['organization_membership_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_org_member_role_member');
        $organizationMembershipRoles->addForeignKeyConstraint('roles', ['role_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_org_member_role_role');
        $organizationMembershipRoles->addForeignKeyConstraint('users', ['granted_by'], ['id'], ['onDelete' => 'SET NULL'], 'fk_org_member_role_granter');

        $events = $schema->createTable('events');
        $events->addColumn('id', Types::STRING, ['length' => 36]);
        $events->addColumn('organization_id', Types::STRING, ['length' => 36]);
        $events->addColumn('name', Types::STRING, ['length' => 160]);
        $events->addColumn('slug', Types::STRING, ['length' => 120]);
        $events->addColumn('starts_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $events->addColumn('ends_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $events->addColumn('status', Types::STRING, ['length' => 32]);
        $events->addColumn('closed_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $events->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $events->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $events->setPrimaryKey(['id']);
        $events->addUniqueIndex(['organization_id', 'slug'], 'uniq_events_org_slug');
        $events->addIndex(['organization_id', 'status'], 'idx_events_org_status');
        $events->addForeignKeyConstraint('organizations', ['organization_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_events_organization');

        $eventMemberships = $schema->createTable('event_memberships');
        $eventMemberships->addColumn('id', Types::STRING, ['length' => 36]);
        $eventMemberships->addColumn('event_id', Types::STRING, ['length' => 36]);
        $eventMemberships->addColumn('user_id', Types::STRING, ['length' => 36]);
        $eventMemberships->addColumn('access_type', Types::STRING, ['length' => 32]);
        $eventMemberships->addColumn('status', Types::STRING, ['length' => 32]);
        $eventMemberships->addColumn('valid_from', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $eventMemberships->addColumn('valid_until', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $eventMemberships->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $eventMemberships->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
        $eventMemberships->setPrimaryKey(['id']);
        $eventMemberships->addUniqueIndex(['event_id', 'user_id'], 'uniq_event_membership');
        $eventMemberships->addIndex(['user_id', 'status'], 'idx_event_membership_user_status');
        $eventMemberships->addForeignKeyConstraint('events', ['event_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_event_membership_event');
        $eventMemberships->addForeignKeyConstraint('users', ['user_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_event_membership_user');

        $eventMembershipRoles = $schema->createTable('event_membership_roles');
        $eventMembershipRoles->addColumn('event_membership_id', Types::STRING, ['length' => 36]);
        $eventMembershipRoles->addColumn('role_id', Types::STRING, ['length' => 36]);
        $eventMembershipRoles->addColumn('granted_by', Types::STRING, ['length' => 36, 'notnull' => false]);
        $eventMembershipRoles->addColumn('granted_at', Types::DATETIME_IMMUTABLE);
        $eventMembershipRoles->setPrimaryKey(['event_membership_id', 'role_id']);
        $eventMembershipRoles->addForeignKeyConstraint('event_memberships', ['event_membership_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_event_member_role_member');
        $eventMembershipRoles->addForeignKeyConstraint('roles', ['role_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_event_member_role_role');
        $eventMembershipRoles->addForeignKeyConstraint('users', ['granted_by'], ['id'], ['onDelete' => 'SET NULL'], 'fk_event_member_role_granter');

        $eventPermissionGrants = $schema->createTable('event_permission_grants');
        $eventPermissionGrants->addColumn('id', Types::STRING, ['length' => 36]);
        $eventPermissionGrants->addColumn('event_membership_id', Types::STRING, ['length' => 36]);
        $eventPermissionGrants->addColumn('permission_id', Types::STRING, ['length' => 36]);
        $eventPermissionGrants->addColumn('constraints_json', Types::JSON, ['notnull' => false]);
        $eventPermissionGrants->addColumn('status', Types::STRING, ['length' => 32]);
        $eventPermissionGrants->addColumn('valid_from', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $eventPermissionGrants->addColumn('valid_until', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $eventPermissionGrants->addColumn('granted_by', Types::STRING, ['length' => 36]);
        $eventPermissionGrants->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $eventPermissionGrants->addColumn('revoked_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $eventPermissionGrants->setPrimaryKey(['id']);
        $eventPermissionGrants->addIndex(['event_membership_id', 'status'], 'idx_event_grant_member_status');
        $eventPermissionGrants->addForeignKeyConstraint('event_memberships', ['event_membership_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_event_grant_membership');
        $eventPermissionGrants->addForeignKeyConstraint('permissions', ['permission_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_event_grant_permission');
        $eventPermissionGrants->addForeignKeyConstraint('users', ['granted_by'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_event_grant_granter');

        $accessInvitations = $schema->createTable('access_invitations');
        $accessInvitations->addColumn('id', Types::STRING, ['length' => 36]);
        $accessInvitations->addColumn('organization_id', Types::STRING, ['length' => 36]);
        $accessInvitations->addColumn('event_id', Types::STRING, ['length' => 36, 'notnull' => false]);
        $accessInvitations->addColumn('email_normalized', Types::STRING, ['length' => 254]);
        $accessInvitations->addColumn('target_access_type', Types::STRING, ['length' => 32]);
        $accessInvitations->addColumn('status', Types::STRING, ['length' => 32]);
        $accessInvitations->addColumn('token_hash', Types::STRING, ['length' => 64]);
        $accessInvitations->addColumn('expires_at', Types::DATETIME_IMMUTABLE);
        $accessInvitations->addColumn('invited_by', Types::STRING, ['length' => 36]);
        $accessInvitations->addColumn('accepted_by', Types::STRING, ['length' => 36, 'notnull' => false]);
        $accessInvitations->addColumn('accepted_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $accessInvitations->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $accessInvitations->setPrimaryKey(['id']);
        $accessInvitations->addUniqueIndex(['token_hash'], 'uniq_access_invitation_token');
        $accessInvitations->addIndex(['organization_id', 'email_normalized', 'status'], 'idx_invitation_org_email_status');
        $accessInvitations->addIndex(['status', 'expires_at'], 'idx_invitation_status_expiry');
        $accessInvitations->addForeignKeyConstraint('organizations', ['organization_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_invitation_organization');
        $accessInvitations->addForeignKeyConstraint('events', ['event_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_invitation_event');
        $accessInvitations->addForeignKeyConstraint('users', ['invited_by'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_invitation_inviter');
        $accessInvitations->addForeignKeyConstraint('users', ['accepted_by'], ['id'], ['onDelete' => 'SET NULL'], 'fk_invitation_acceptor');

        $auditLogs = $schema->createTable('audit_logs');
        $auditLogs->addColumn('id', Types::STRING, ['length' => 36]);
        $auditLogs->addColumn('organization_id', Types::STRING, ['length' => 36]);
        $auditLogs->addColumn('event_id', Types::STRING, ['length' => 36, 'notnull' => false]);
        $auditLogs->addColumn('actor_user_id', Types::STRING, ['length' => 36, 'notnull' => false]);
        $auditLogs->addColumn('action', Types::STRING, ['length' => 64]);
        $auditLogs->addColumn('aggregate_type', Types::STRING, ['length' => 64]);
        $auditLogs->addColumn('aggregate_id', Types::STRING, ['length' => 36, 'notnull' => false]);
        $auditLogs->addColumn('request_id', Types::STRING, ['length' => 64, 'notnull' => false]);
        $auditLogs->addColumn('metadata_json', Types::JSON, ['notnull' => false]);
        $auditLogs->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $auditLogs->setPrimaryKey(['id']);
        $auditLogs->addIndex(['organization_id', 'created_at'], 'idx_audit_org_created');
        $auditLogs->addIndex(['event_id', 'created_at'], 'idx_audit_event_created');
        $auditLogs->addIndex(['actor_user_id', 'created_at'], 'idx_audit_actor_created');
        $auditLogs->addForeignKeyConstraint('organizations', ['organization_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_audit_organization');
        $auditLogs->addForeignKeyConstraint('events', ['event_id'], ['id'], ['onDelete' => 'RESTRICT'], 'fk_audit_event');
        $auditLogs->addForeignKeyConstraint('users', ['actor_user_id'], ['id'], ['onDelete' => 'SET NULL'], 'fk_audit_actor');
    }

    public function down(Schema $schema): void
    {
        foreach ([
            'audit_logs',
            'access_invitations',
            'event_permission_grants',
            'event_membership_roles',
            'event_memberships',
            'events',
            'organization_membership_roles',
            'organization_memberships',
            'role_permissions',
            'permissions',
            'roles',
            'organizations',
            'users',
        ] as $table) {
            $schema->dropTable($table);
        }
    }
}
