<?php

namespace SilverStripe\LDAP\Extensions;

use Exception;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\Forms\TextField;
use SilverStripe\LDAP\Services\LDAPService;
use SilverStripe\ORM\DataExtension;
use SilverStripe\ORM\ValidationException;
use SilverStripe\ORM\ValidationResult;

/**
 * Class LDAPMemberExtension.
 *
 * Adds mappings from AD attributes to SilverStripe {@link Member} fields.
 *
 * @package activedirectory
 */
class LDAPMemberExtension extends DataExtension
{
    /**
     * @var array
     */
    private static $db = [
        // Unique user identifier
        'GUID' => 'Varchar(50)',
        'Username' => 'Varchar(64)',
        'IsExpired' => 'Boolean',
        'LastSynced' => 'DBDatetime',
    ];

    /**
     * These fields are used by {@link LDAPMemberSync} to map specific AD attributes
     * to {@link Member} fields.
     *
     * @var array
     * @config
     */
    private static $ldap_field_mappings = [
        'givenName' => 'FirstName',
        'uid' => 'Username',
        'sn' => 'Surname',
        'mail' => 'Email',
    ];

    /**
     * The location (relative to /assets) where to save thumbnailphoto data.
     *
     * @var string
     * @config
     */
    private static $ldap_thumbnail_path = 'Uploads';

    /**
     * When enabled, LDAP managed Member records (GUID flag)
     * have their data written back to LDAP on write, and synchronise
     * membership to groups mapped to LDAP.
     *
     * Keep in mind this will currently NOT trigger if there are no
     * field changes due to onAfterWrite in framework not being called
     * when there are no changes.
     *
     * This requires setting write permissions on the user configured in the LDAP
     * credentials, which is why this is disabled by default.
     *
     * @var bool
     * @config
     */
    private static $update_ldap_from_local = false;

    /**
     * If enabled, Member records with a Username field have the user created in LDAP
     * on write.
     *
     * This requires setting write permissions on the user configured in the LDAP
     * credentials, which is why this is disabled by default.
     *
     * @var bool
     * @config
     */
    private static $create_users_in_ldap = false;

    /**
     * If enabled, deleting Member records mapped to LDAP deletes the LDAP user.
     *
     * This requires setting write permissions on the user configured in the LDAP
     * credentials, which is why this is disabled by default.
     *
     * @var bool
     * @config
     */
    private static $delete_users_in_ldap = false;

    /**
     * If enabled, this allows the afterMemberLoggedIn() call to fail to update the user without causing a login failure
     * and server error. This can be useful when not all of your web servers have access to the LDAP server (for example
     * when your front-line web servers are not the servers that perform the LDAP sync into the database.
     *
     * Note: If this is enabled, you *must* ensure that a regular sync of both groups and users is carried out by
     * running the LDAPGroupSyncTask and LDAPMemberSyncTask. If not, there is no guarantee that this user can still have
     * all the permissions that they previously had.
     *
     * Security risk: If this is enabled, then users who are removed from groups may not have their group membership or
     * other information updated until the aforementioned LDAPGroupSyncTask and LDAPMemberSyncTask build tasks are run,
     * which can lead to users having incorrect permissions until the next sync happens.
     *
     * @var bool
     * @config
     */
    private static $allow_update_failure_during_login = false;

    /**
     * @param FieldList $fields
     */
    public function updateCMSFields(FieldList $fields)
    {
        // Redo LDAP metadata fields as read-only and move to LDAP tab.
        $ldapMetadata = [];
        $fields->replaceField('GUID', $ldapMetadata[] = ReadonlyField::create('GUID'));
        $fields->replaceField(
            'IsExpired',
            $ldapMetadata[] = ReadonlyField::create(
                'IsExpired',
                _t(__CLASS__ . '.ISEXPIRED', 'Has user\'s LDAP/AD login expired?')
            )
        );
        $fields->replaceField(
            'LastSynced',
            $ldapMetadata[] = ReadonlyField::create(
                'LastSynced',
                _t(__CLASS__ . '.LASTSYNCED', 'Last synced')
            )
        );
        $fields->addFieldsToTab('Root.LDAP', $ldapMetadata);

        $message = '';
        if ($this->owner->GUID && $this->owner->config()->update_ldap_from_local) {
            $message = _t(
                __CLASS__ . '.CHANGEFIELDSUPDATELDAP',
                'Changing fields here will update them in LDAP.'
            );
        } elseif ($this->owner->GUID && !$this->owner->config()->update_ldap_from_local) {
            // Transform the automatically mapped fields into read-only. This doesn't
            // apply if updating LDAP from local is enabled, as changing data locally can be written back.
            foreach ($this->owner->config()->ldap_field_mappings as $name) {
                $field = $fields->dataFieldByName($name);
                if (!empty($field)) {
                    // Set to readonly, but not disabled so that the data is still sent to the
                    // server and doesn't break Member_Validator
                    $field->setReadonly(true);
                    $field->setTitle($field->Title() . _t(__CLASS__ . '.IMPORTEDFIELD', ' (imported)'));
                }
            }
            $message = _t(
                __CLASS__ . '.INFOIMPORTED',
                'This user is automatically imported from LDAP. ' .
                    'Manual changes to imported fields will be removed upon sync.'
            );
        }
        if ($message) {
            $fields->addFieldToTab(
                'Root.Main',
                LiteralField::create(
                    'Info',
                    sprintf('<p class="alert alert-warning">%s</p>', $message)
                ),
                'FirstName'
            );
        }

        // Ensure blog fields are added after first and last name
        $fields->addFieldToTab(
            'Root.Main',
            TextField::create('Username'),
            'Email'
        );
    }

    /**
     * @param  ValidationResult
     * @throws ValidationException
     */
    public function validate(ValidationResult $validationResult)
    {
        // We allow empty Username for registration purposes, as we need to
        // create Member records with empty Username temporarily. Forms should explicitly
        // check for Username not being empty if they require it not to be.
        if (empty($this->owner->Username) || !$this->owner->config()->create_users_in_ldap) {
            return;
        }

        if (!preg_match('/^[a-z0-9\.]+$/', $this->owner->Username)) {
            $validationResult->addError(
                'Username must only contain lowercase alphanumeric characters and dots.',
                'bad'
            );
            throw new ValidationException($validationResult);
        }
    }

    /**
     * Create the user in LDAP, provided this configuration is enabled
     * and a username was passed to a new Member record.
     */
    public function onBeforeWrite()
    {
        if ($this->owner->LDAPMemberExtension_NoSync) {
            return;
        }

        $service = Injector::inst()->get(LDAPService::class);
        if (!$service->enabled()
            || !$this->owner->config()->create_users_in_ldap
            || !$this->owner->Username
            || $this->owner->GUID
        ) {
            return;
        }

        $service->createLDAPUser($this->owner);
    }

    public function onAfterWrite()
    {
        if ($this->owner->LDAPMemberExtension_NoSync) {
            return;
        }

        $service = Injector::inst()->get(LDAPService::class);
        if (!$service->enabled() ||
            !$this->owner->config()->update_ldap_from_local ||
            !$this->owner->GUID
        ) {
            return;
        }
        $this->sync();
    }

    public function onAfterDelete()
    {
        if ($this->owner->LDAPMemberExtension_NoSync) {
            return;
        }

        $service = Injector::inst()->get(LDAPService::class);
        if (!$service->enabled() ||
            !$this->owner->config()->delete_users_in_ldap ||
            !$this->owner->GUID
        ) {
            return;
        }

        $service->deleteLDAPMember($this->owner);
    }

    /**
     * Write DataObject without triggering this extension's hooks.
     *
     * @throws Exception
     */
    public function writeWithoutSync()
    {
        $this->owner->LDAPMemberExtension_NoSync = true;
        try {
            $this->owner->write();
        } finally {
            $this->owner->LDAPMemberExtension_NoSync = false;
        }
    }

    /**
     * Update the local data with LDAP, and ensure local membership is also set in
     * LDAP too. This writes into LDAP, provided that feature is enabled.
     */
    public function sync()
    {
        $service = Injector::inst()->get(LDAPService::class);
        if (!$service->enabled() ||
            !$this->owner->GUID
        ) {
            return;
        }
        $service->updateLDAPFromMember($this->owner);
        $service->updateLDAPGroupsForMember($this->owner);
    }

    /**
     * @deprecated 1.1.0 Not used by SilverStripe internally and will be removed in 2.0
     */
    public function memberLoggedIn()
    {
        return $this->afterMemberLoggedIn();
    }

    /**
     * Triggered by {@link IdentityStore::logIn()}. When successfully logged in,
     * this will update the Member record from LDAP data.
     *
     * @throws Exception When failures are not acceptable via configuration
     */
    public function afterMemberLoggedIn()
    {
        if ($this->owner->GUID) {
            try {
                Injector::inst()->get(LDAPService::class)->updateMemberFromLDAP($this->owner);
            } catch (Exception $e) {
                // If the failure is acceptable, then ignore it and return. Otherwise, re-throw the exception
                if ($this->owner->config()->get('allow_update_failure_during_login')) {
                    return;
                }
                throw $e;
            }
        }
    }

    /**
     * Synchronise password changes to AD when they happen in SilverStripe
     *
     * @param string           $newPassword
     * @param ValidationResult $validation
     */
    public function onBeforeChangePassword($newPassword, $validation)
    {
        // Don't do anything if there's already a validation failure
        if (!$validation->isValid()) {
            return;
        }

        Injector::inst()->get(LDAPService::class)
            ->setPassword($this->owner, $newPassword);
    }
}
