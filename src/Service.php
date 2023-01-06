<?php

declare(strict_types=1);

namespace Brave\Neucore\Plugin\Mumble;

use Neucore\Plugin\Core\FactoryInterface;
use Neucore\Plugin\Data\CoreAccount;
use Neucore\Plugin\Data\CoreCharacter;
use Neucore\Plugin\Data\CoreGroup;
use Neucore\Plugin\Data\PluginConfiguration;
use Neucore\Plugin\Data\ServiceAccountData;
use Neucore\Plugin\Exception;
use Neucore\Plugin\ServiceInterface;
use PDO;
use PDOException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/** @noinspection PhpUnused */
class Service implements ServiceInterface
{
    private LoggerInterface $logger;

    private string $configurationData;

    private ?PDO $pdo = null;

    private ?array $groupsToTags = null;

    public function __construct(
        LoggerInterface $logger,
        PluginConfiguration $pluginConfiguration,
        FactoryInterface $factory,
    ) {
        $this->logger = $logger;
        $this->configurationData = $pluginConfiguration->configurationData;
    }

    public function onConfigurationChange(): void
    {
    }

    /**
     * @throws Exception
     */
    public function request(
        string $name,
        ServerRequestInterface $request,
        ResponseInterface $response,
        ?CoreAccount $coreAccount,
    ): ResponseInterface {
        throw new Exception();
    }

    /**
     * @param CoreCharacter[] $characters
     * @return ServiceAccountData[]
     * @throws Exception
     */
    public function getAccounts(array $characters): array
    {
        if (count($characters) === 0) {
            return [];
        }

        $this->dbConnect();

        $characterIdsOwnerHashes = [];
        foreach ($characters as $character) {
            $characterIdsOwnerHashes[$character->id] = $character->ownerHash;
        }

        $placeholders = implode(',', array_fill(0, count($characterIdsOwnerHashes), '?'));
        $stmt = $this->pdo->prepare(
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
            if (
                !empty($characterIdsOwnerHashes[$characterId]) &&
                $row['owner_hash'] !== $characterIdsOwnerHashes[$characterId]
            ) {
                $password = $this->updateOwner($characterId, $characterIdsOwnerHashes[$characterId]);
            }
            $result[] = new ServiceAccountData($characterId, $row['mumble_username'], $password);
        }

        return $result;
    }

    /**
     * @param CoreGroup[] $groups
     * @param int[] $allCharacterIds
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

        $this->dbConnect();

        // add ticker
        $this->addTicker($character);

        // add user
        $mumbleUsername = $this->toMumbleName($character->name);
        $mumblePassword = $this->randomString();
        $stmt = $this->pdo->prepare(
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

    public function updateAccount(CoreCharacter $character, array $groups, ?CoreCharacter $mainCharacter): void
    {
        $this->dbConnect();

        $groupNames = $this->groupNames($groups);

        // add ticker
        $this->addTicker($character);

        // There are some accounts with an empty Mumble username, update it if empty, but do not change it if not.
        $stmtSelect = $this->pdo->prepare("SELECT mumble_username FROM user WHERE character_id = :id");
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
        $stmt = $this->pdo->prepare(
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

        // Add/remove character from ban table
        $banFilter = "character-$character->id";
        if (in_array((int)($_ENV['NEUCORE_PLUGIN_MUMBLE_BANNED_GROUP'] ?? 0), $this->groupIds($groups))) {
            $stmt = $this->pdo->prepare('INSERT IGNORE INTO ban (filter, reason_public) VALUES (:filter, :reason)');
            $stmt->bindValue(':reason', 'banned');
        } else {
            $stmt = $this->pdo->prepare('DELETE FROM ban WHERE filter = :filter');
        }
        $stmt->bindValue(':filter', $banFilter);
        try {
            $stmt->execute();
        } catch(\Exception $e) {
            $this->logger->error($e->getMessage(), ['exception' => $e]);
            throw new Exception();
        }
    }

    public function updatePlayerAccount(CoreCharacter $mainCharacter, array $groups): void
    {
        throw new Exception();
    }

    public function moveServiceAccount(int $toPlayerId, int $fromPlayerId): bool
    {
        return true;
    }

    public function resetPassword(int $characterId): string
    {
        $this->dbConnect();

        $newPassword = $this->randomString();

        $stmt = $this->pdo->prepare(
            'UPDATE user SET mumble_password = :mumble_password WHERE character_id = :character_id'
        );
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
        $this->dbConnect();

        $stmt = $this->pdo->prepare("SELECT character_id FROM user ORDER BY updated_at");
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

    public function getAllPlayerAccounts(): array
    {
        return [];
    }

    public function search(string $query): array
    {
        return [];
    }

    private function updateOwner(int $characterId, string $newOwnerHash): ?string
    {
        $password = $this->randomString();

        $stmt = $this->pdo->prepare(
            'UPDATE user SET owner_hash = :hash, mumble_password = :pw WHERE character_id = :id'
        );
        try {
            $stmt->execute([':id' => $characterId, ':pw' => $password, ':hash' => $newOwnerHash]);
        } catch (PDOException $e) {
            $this->logger->error($e->getMessage(), ['exception' => $e]);
            return null;
        }

        return $password;
    }

    private function addTicker(CoreCharacter $character): void
    {
        foreach ([
             'corporation' => [$character->corporationId, $character->corporationTicker],
             'alliance' => [$character->allianceId, $character->allianceTicker]
         ] as $type => $ticker) {
            if (empty($ticker[0]) || empty($ticker[1])) {
                continue;
            }
            $stmt = $this->pdo->prepare(
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

    /**
     * @param CoreGroup[] $groups
     */
    private function groupNames(array $groups): string
    {
        return implode(',', array_map(function (CoreGroup $group) {
            return $group->name;
        }, $groups));
    }

    /**
     * @param CoreGroup[] $groups
     * @return int[]
     */
    private function groupIds(array $groups): array
    {
        return array_map(function (CoreGroup $group) {
            return $group->identifier;
        }, $groups);
    }

    /**
     * @throws Exception
     */
    private function toMumbleName(string $characterName): string
    {
        $name = strtolower(preg_replace("/[^A-Za-z0-9\-]/", '_', $characterName));
        $nameToCheck = $name;

        $unique = false;
        $count = 0;
        while (!$unique && $count < 100) {
            $stmt = $this->pdo->prepare('SELECT 1 FROM user WHERE mumble_username = ?');
            try {
                $stmt->execute([$nameToCheck]);
            } catch (PDOException $e) {
                $this->logger->error($e->getMessage(), ['exception' => $e]);
                throw new Exception();
            }
            if ($stmt->rowCount() === 0) {
                $unique = true;
            } else {
                $count ++;
                $nameToCheck = "{$name}_$count";
            }
        }

        return $nameToCheck;
    }

    private function generateMumbleFullName(
        string $characterName,
        string $groups,
        string $corporationTicker = null
    ): string {
        $groupsArray = explode(',', $groups);
        $pronouns = ['He/Him', 'She/He', 'She/Her', 'They/Them', 'He/They', 'She/They', 'Any Pronouns'];

        $pronoun = '';
        $ceo = '';
        $appendix = $corporationTicker ? " [$corporationTicker]" : '';
        $foundAppendix = false;
        foreach ($this->groupsToTags() as $group => $assignedTag) {
            if (!in_array($group, $groupsArray)) {
                continue;
            }
            if ($assignedTag === 'CEO') {
                $ceo = " (CEO)";
            }
            if (in_array($assignedTag, $pronouns)) {
                $pronoun = " ($assignedTag)";
            }
            if (!$foundAppendix && $assignedTag !== 'CEO' && !in_array($assignedTag, $pronouns)) {
                $appendix = " ($assignedTag)";
                $foundAppendix = true; // first one wins
            }
        }

        return $characterName . $pronoun . $ceo . $appendix;
    }

    private function randomString(): string
    {
        $characters = "abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789";
        $max = mb_strlen($characters) - 1;
        $pass = '';
        for ($i = 0; $i < 10; $i++) {
            try {
                $pass .= $characters[random_int(0, $max)];
            } catch (\Exception) {
                $pass .= $characters[rand(0, $max)];
            }
        }
        return $pass;
    }

    /**
     * @throws Exception
     */
    private function dbConnect(): void
    {
        if ($this->pdo === null) {
            try {
                $this->pdo = new PDO(
                    $_ENV['NEUCORE_PLUGIN_MUMBLE_DB_DSN'],
                    $_ENV['NEUCORE_PLUGIN_MUMBLE_DB_USERNAME'],
                    $_ENV['NEUCORE_PLUGIN_MUMBLE_DB_PASSWORD']
                );
            } catch (PDOException $e) {
                $this->logger->error($e->getMessage() . ' at ' . __FILE__ . ':' . __LINE__);
                throw new Exception();
            }
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }
    }

    private function groupsToTags(): array
    {
        if (is_array($this->groupsToTags)) {
            return $this->groupsToTags;
        }

        $this->groupsToTags = [];
        foreach (explode("\n", $this->configurationData) as $line) {
            $separatorPos = strpos($line, ':');
            if ($separatorPos === false) {
                continue;
            }
            $group = trim(substr($line, 0, $separatorPos));
            $title = trim(substr($line, $separatorPos + 1));
            if (!empty($group) && !empty($title)) {
                $this->groupsToTags[$group] = $title;
            }
        }

        return $this->groupsToTags;
    }
}
