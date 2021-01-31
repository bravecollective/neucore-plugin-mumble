<?php

declare(strict_types=1);

namespace Brave\Neucore\Plugin\Mumble;

use Neucore\Plugin\CoreCharacter;
use Neucore\Plugin\CoreGroup;
use Neucore\Plugin\Exception;
use Neucore\Plugin\ServiceAccountData;
use Neucore\Plugin\ServiceInterface;
use PDO;
use PDOException;
use Psr\Log\LoggerInterface;

/** @noinspection PhpUnused */
class Service implements ServiceInterface
{
    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var PDO|null
     */
    private $pdo;

    /**
     * @var array|null
     */
    private $groupsToTags;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @param CoreCharacter[] $characters
     * @param CoreGroup[] $groups
     * @return ServiceAccountData[]
     * @throws Exception
     */
    public function getAccounts(array $characters, array $groups): array
    {
        if (count($characters) === 0) {
            return [];
        }

        $pdo = $this->dbConnect();
        if ($pdo === null) {
            throw new Exception();
        }

        $characterIdsOwnerHashes = [];
        foreach ($characters as $character) {
            $characterIdsOwnerHashes[$character->id] = $character->ownerHash;
        }

        $placeholders = implode(',', array_fill(0, count($characterIdsOwnerHashes), '?'));
        $stmt = $pdo->prepare(
            "SELECT character_id, mumble_username, mumble_password, owner_hash
            FROM user
            WHERE character_id IN ($placeholders)"
        );
        try {
            $stmt->execute(array_keys($characterIdsOwnerHashes));
        } catch (PDOException $e) {
            $this->logger->error($e->getMessage(), ['exception' => $e]);
            throw new Exception();
        }

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $characterId = (int)$row['character_id'];
            $password = $row['mumble_password'];
            if ($row['owner_hash'] !== $characterIdsOwnerHashes[$characterId]) {
                $password = $this->updateOwner($pdo, $characterId, $characterIdsOwnerHashes[$characterId]);
            }
            $result[] = new ServiceAccountData($characterId, $row['mumble_username'], $password);
        }

        return $result;
    }

    /**
     * @param CoreGroup[] $groups
     * @param int[] $allCharacterIds
     * @return ServiceAccountData
     * @throws Exception
     */
    public function register(
        CoreCharacter $character,
        array $groups,
        string $emailAddress,
        array $allCharacterIds
    ): ServiceAccountData {
        if (empty($character->name)) {
            throw new Exception();
        }

        $pdo = $this->dbConnect();
        if ($pdo === null) {
            throw new Exception();
        }

        // add ticker
        $this->addTicker($pdo, $character);

        // add user
        $mumbleUsername = $this->toMumbleName($character->name);
        $mumblePassword = $this->randomString(10);
        $stmt = $pdo->prepare(
            'INSERT INTO user (character_id, character_name, corporation_id, corporation_name, 
                  alliance_id, alliance_name, mumble_username, mumble_password, `groups`, created_at, 
                  updated_at, owner_hash, mumble_fullname) 
              VALUES (:character_id, :character_name, :corporation_id, :corporation_name, 
                      :alliance_id, :alliance_name, :mumble_username, :mumble_password, :groups, :created_at, 
                      :updated_at, :owner_hash, :mumble_fullname)'
        );
        $created = time();
        $groupNames = $this->groupNames($groups);
        $stmt->bindValue(':character_id', $character->id);
        $stmt->bindValue(':character_name', $character->name);
        $stmt->bindValue(':corporation_id', (int)$character->corporationId);
        $stmt->bindValue(':corporation_name', (string)$character->corporationName);
        $stmt->bindValue(':alliance_id', $character->allianceId);
        $stmt->bindValue(':alliance_name', $character->allianceName);
        $stmt->bindValue(':mumble_username', $mumbleUsername);
        $stmt->bindValue(':mumble_password', $mumblePassword);
        $stmt->bindValue(':groups', $groupNames);
        $stmt->bindValue(':created_at', $created);
        $stmt->bindValue(':updated_at', $created);
        $stmt->bindValue(':owner_hash', (string)$character->ownerHash);
        $stmt->bindValue(
            ':mumble_fullname',
            $this->generateMumbleFullName($character->name, $groupNames, $character->corporationTicker)
        );
        try {
            $stmt->execute();
        } catch(\Exception $e) {
            $this->logger->error($e->getMessage(), ['exception' => $e]);
            throw new Exception();
        }

        return new ServiceAccountData($character->id, $mumbleUsername, $mumblePassword);
    }

    public function updateAccount(CoreCharacter $character, array $groups): void
    {
        $pdo = $this->dbConnect();
        if ($pdo === null) {
            throw new Exception();
        }

        $groupNames = $this->groupNames($groups);

        // add ticker
        $this->addTicker($pdo, $character);

        // There are some accounts with an empty Mumble username, update it if empty, but do not change it if not.
        $stmtSelect = $pdo->prepare("SELECT mumble_username FROM user WHERE character_id = :id");
        try {
            $stmtSelect->execute([':id' => $character->id]);
        } catch (PDOException $e) {
            $this->logger->error($e->getMessage(), ['exception' => $e]);
            throw new Exception();
        }
        $userNameResult = $stmtSelect->fetchAll(PDO::FETCH_ASSOC);
        if (isset($userNameResult[0]) && !empty($userNameResult[0]['mumble_username'])) {
            $mumbleUsername = $userNameResult[0]['mumble_username'];
        } else {
            $mumbleUsername = $this->toMumbleName((string)$character->name);
            // the username may still be empty here
        }
        $updateUserNameSqlPart = empty($mumbleUsername) ? '' : 'mumble_username = :mumble_username,';

        // Character name and Mumble full name - $character->name can be null!
        $mumbleFullName = $this->generateMumbleFullName(
            (string)$character->name,
            $groupNames,
            $character->corporationTicker
        );
        $updateFullNameSqlPart = empty($mumbleFullName) ? '' : 'mumble_fullname = :mumble_fullname,';
        $updateCharNameSqlPart = empty($character->name) ? '' : 'character_name = :character_name,';

        // update user
        $stmt = $pdo->prepare(
            "UPDATE user
            SET `groups` = :groups, $updateCharNameSqlPart
                corporation_id = :corporation_id, corporation_name = :corporation_name,
                alliance_id = :alliance_id, alliance_name = :alliance_name,
                $updateUserNameSqlPart $updateFullNameSqlPart
                updated_at = :updated_at
            WHERE character_id = :character_id"
        );
        $stmt->bindValue(':character_id', $character->id);
        if (!empty($character->name)) {
            $stmt->bindValue(':character_name', $character->name);
        }
        $stmt->bindValue(':corporation_id', (int)$character->corporationId);
        $stmt->bindValue(':corporation_name', (string)$character->corporationName);
        $stmt->bindValue(':alliance_id', $character->allianceId);
        $stmt->bindValue(':alliance_name', $character->allianceName);
        $stmt->bindValue(':groups', $groupNames);
        $stmt->bindValue(':updated_at', time());
        if (!empty($mumbleUsername)) {
            $stmt->bindValue(':mumble_username', $mumbleUsername);
        }
        if (!empty($mumbleFullName)) {
            $stmt->bindValue(':mumble_fullname', $mumbleFullName);
        }
        try {
            $stmt->execute();
        } catch(\Exception $e) {
            $this->logger->error($e->getMessage(), ['exception' => $e]);
            throw new Exception();
        }
    }

    public function resetPassword(int $characterId): string
    {
        $pdo = $this->dbConnect();
        if ($pdo === null) {
            throw new Exception();
        }

        $newPassword = $this->randomString(10);

        $stmt = $pdo->prepare('UPDATE user SET mumble_password = :mumble_password WHERE character_id = :character_id');
        try {
            $stmt->execute([':character_id' => $characterId, ':mumble_password' => $newPassword]);
        } catch (PDOException $e) {
            $this->logger->error($e->getMessage(), ['exception' => $e]);
            throw new Exception();
        }

        return $newPassword;
    }

    public function getAllAccounts(): array
    {
        $pdo = $this->dbConnect();
        if ($pdo === null) {
            throw new Exception();
        }

        $stmt = $pdo->prepare("SELECT character_id FROM user ORDER BY updated_at");
        try {
            $stmt->execute();
        } catch (PDOException $e) {
            $this->logger->error($e->getMessage(), ['exception' => $e]);
            throw new Exception();
        }

        return array_map(function (array $row) {
            return (int)$row['character_id'];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function updateOwner(PDO $pdo, int $characterId, string $newOwnerHash): ?string
    {
        $password = $this->randomString(10);

        $stmt = $pdo->prepare('UPDATE user SET owner_hash = :hash, mumble_password = :pw WHERE character_id = :id');
        try {
            $stmt->execute([':id' => $characterId, ':pw' => $password, ':hash' => $newOwnerHash]);
        } catch (PDOException $e) {
            $this->logger->error($e->getMessage(), ['exception' => $e]);
            return null;
        }

        return $password;
    }

    private function addTicker(PDO $pdo, CoreCharacter $character): void
    {
        foreach ([
             'corporation' => [$character->corporationId, $character->corporationTicker],
             'alliance' => [$character->allianceId, $character->allianceTicker]
         ] as $type => $ticker) {
            if (empty($ticker[0]) || empty($ticker[1])) {
                continue;
            }
            $stmt = $pdo->prepare(
                'INSERT INTO ticker (filter, text) 
                VALUES (:filter, :text) 
                ON DUPLICATE KEY UPDATE text = :text'
            );
            $stmt->bindValue(':filter', $type . '-' . $ticker[0]);
            $stmt->bindValue(':text', $ticker[1]);
            try {
                $stmt->execute();
            } catch(PDOException $e) {
                $this->logger->error($e->getMessage(), ['exception' => $e]);
            }
        }
    }

    private function groupNames(array $groups): string
    {
        return implode(',', array_map(function (CoreGroup $group) {
            return $group->name;
        }, $groups));
    }

    private function toMumbleName(string $characterName): string
    {
        return strtolower(preg_replace("/[^A-Za-z0-9\-]/", '_', $characterName));
    }

    private function generateMumbleFullName(
        string $characterName,
        string $groups,
        string $corporationTicker = null
    ): string {
        $appendix = $corporationTicker ? " [$corporationTicker]" : '';
        $groupsArray = explode(',', $groups);
        foreach ($this->groupsToTags() as $group => $assignedTag) {
            if (in_array($group, $groupsArray)) {
                $appendix = " ($assignedTag)";
                // first one wins
                break;
            }
        }
        return $characterName . $appendix;
    }

    private function randomString(int $length): string
    {
        $characters = "abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789";
        $max = mb_strlen($characters) - 1;
        $pass = '';
        for ($i = 0; $i < $length; $i++) {
            try {
                $pass .= $characters[random_int(0, $max)];
            } catch (\Exception $e) {
                $pass .= $characters[rand(0, $max)];
            }
        }
        return $pass;
    }

    private function dbConnect(): ?PDO
    {
        if ($this->pdo === null) {
            try {
                $this->pdo = new PDO(
                    $_ENV['NEUCORE_PLUGIN_MUMBLE_DB_DSN'],
                    $_ENV['NEUCORE_PLUGIN_MUMBLE_DB_USERNAME'],
                    $_ENV['NEUCORE_PLUGIN_MUMBLE_DB_PASSWORD']
                );
            } catch (PDOException $e) {
                $this->logger->error($e->getMessage(), ['exception' => $e]);
                return null;
            }

            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }

        return $this->pdo;
    }

    private function groupsToTags(): array
    {
        if (!is_array($this->groupsToTags)) {
            /** @noinspection PhpIncludeInspection */
            $this->groupsToTags = include $_ENV['NEUCORE_PLUGIN_MUMBLE_CONFIG_FILE'];
        }

        return $this->groupsToTags;
    }
}
