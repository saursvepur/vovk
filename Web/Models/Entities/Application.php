<?php

declare(strict_types=1);

namespace openvk\Web\Models\Entities;

use Chandler\Database\DatabaseConnection;
use Nette\Utils\Image;
use Nette\Utils\UnknownImageFileException;
use openvk\Web\Models\Repositories\Notes;
use openvk\Web\Models\Repositories\Users;
use openvk\Web\Models\RowModel;

class Application extends RowModel
{
    protected $tableName = "apps";

    public const PERMS = [
        "notify",
        "friends",
        "photos",
        "audio",
        "video",
        "stories",
        "pages",
        "status",
        "notes",
        "messages",
        "wall",
        "ads",
        "docs",
        "groups",
        "notifications",
        "stats",
        "email",
        "market",
    ];

    private function getAvatarsDir(): string
    {
        $uploadSettings = OPENVK_ROOT_CONF["openvk"]["preferences"]["uploads"];
        if ($uploadSettings["mode"] === "server" && $uploadSettings["server"]["kind"] === "cdn") {
            return $uploadSettings["server"]["directory"];
        } else {
            return OPENVK_ROOT . "/storage/";
        }
    }

    public function getId(): int
    {
        return $this->getRecord()->id;
    }

    public function getOwner(): User
    {
        return (new Users())->get($this->getRecord()->owner);
    }

    public function getName(): string
    {
        return $this->getRecord()->name;
    }

    public function getDescription(): string
    {
        return $this->getRecord()->description;
    }

    public function getAvatarUrl(): string
    {
        $serverUrl = ovk_scheme(true) . $_SERVER["HTTP_HOST"];
        if (is_null($this->getRecord()->avatar_hash)) {
            return "$serverUrl/assets/packages/static/openvk/img/camera_200.png";
        }

        $hash = $this->getRecord()->avatar_hash;
        switch (OPENVK_ROOT_CONF["openvk"]["preferences"]["uploads"]["mode"]) {
            default:
            case "default":
            case "basic":
                return "$serverUrl/blob_" . substr($hash, 0, 2) . "/$hash" . "_app_avatar.png";
            case "accelerated":
                return "$serverUrl/openvk-datastore/$hash" . "_app_avatar.png";
            case "server":
                $settings = (object) OPENVK_ROOT_CONF["openvk"]["preferences"]["uploads"]["server"];
                return (
                    $settings->protocol ?? ovk_scheme() .
                    "://" . $settings->host .
                    $settings->path .
                    substr($hash, 0, 2) . "/$hash" . "_app_avatar.png"
                );
        }
    }

    public function getNote(): ?Note
    {
        if (!$this->getRecord()->news) {
            return null;
        }

        return (new Notes())->get($this->getRecord()->news);
    }

    public function getNoteLink(): string
    {
        $note = $this->getNote();
        if (!$note) {
            return "";
        }

        return ovk_scheme(true) . $_SERVER["HTTP_HOST"] . "/note" . $note->getPrettyId();
    }

    public function getBalance(): float
    {
        return $this->getRecord()->coins;
    }

    public function getURL(): string
    {
        return $this->getRecord()->address;
    }

    public function getOrigin(): string
    {
        $parsed = parse_url($this->getURL());

        return (
            ($parsed["scheme"] ?? "https") . "://"
            . ($parsed["host"] ?? "127.0.0.1") . ":"
            . ($parsed["port"] ?? "443")
        );
    }

    public function getUsersCount(): int
    {
        $cx = DatabaseConnection::i()->getContext();
        return sizeof($cx->table("app_users")->where("app", $this->getId()));
    }

    public function getInstallationEntry(User $user): ?array
    {
        $cx    = DatabaseConnection::i()->getContext();
        $entry = $cx->table("app_users")->where([
            "app"  => $this->getId(),
            "user" => $user->getId(),
        ])->fetch();

        if (!$entry) {
            return null;
        }

        return $entry->toArray();
    }

    public function getPermissions(User $user): array
    {
        $permMask    = 0;
        $installInfo = $this->getInstallationEntry($user);
        if (!$installInfo) {
            $this->install($user);
        } else {
            $permMask = $installInfo["access"];
        }

        $res = [];
        for ($i = 0; $i < sizeof(self::PERMS); $i++) {
            $checkVal = 1 << $i;
            if (($permMask & $checkVal) > 0) {
                $res[] = self::PERMS[$i];
            }
        }

        return $res;
    }

    public function isInstalledBy(User $user): bool
    {
        return !is_null($this->getInstallationEntry($user));
    }

    public function setNoteLink(?string $link): bool
    {
        if (!$link) {
            $this->stateChanges("news", null);

            return true;
        }

        preg_match("%note([0-9]+)_([0-9]+)$%", $link, $matches);
        if (sizeof($matches) != 3) {
            return false;
        }

        $owner = is_null($this->getRecord()) ? $this->changes["owner"] : $this->getRecord()->owner;
        [, $ownerId, $vid] = $matches;
        if ($ownerId != $owner) {
            return false;
        }

        $note = (new Notes())->getNoteById((int) $ownerId, (int) $vid);
        if (!$note) {
            return false;
        }

        $this->stateChanges("news", $note->getId());

        return true;
    }

    public function setAvatar(array $file): int
    {
        if ($file["error"] !== UPLOAD_ERR_OK) {
            return -1;
        }

        try {
            $image = Image::fromFile($file["tmp_name"]);
        } catch (UnknownImageFileException $e) {
            return -2;
        }

        $hash = hash_file("adler32", $file["tmp_name"]);
        if (!is_dir($this->getAvatarsDir() . substr($hash, 0, 2))) {
            if (!mkdir($this->getAvatarsDir() . substr($hash, 0, 2))) {
                return -3;
            }
        }

        $image->resize(140, 140, Image::STRETCH);
        $image->save($this->getAvatarsDir() . substr($hash, 0, 2) . "/$hash" . "_app_avatar.png");

        $this->stateChanges("avatar_hash", $hash);

        return 0;
    }

    public function setPermission(User $user, string $perm, bool $enabled): bool
    {
        $permMask    = 0;
        $installInfo = $this->getInstallationEntry($user);
        if (!$installInfo) {
            $this->install($user);
        } else {
            $permMask = $installInfo["access"];
        }

        $index = array_search($perm, self::PERMS);
        if ($index === false) {
            return false;
        }

        $permVal  = 1 << $index;
        $permMask = $enabled ? ($permMask | $permVal) : ($permMask ^ $permVal);

        $cx = DatabaseConnection::i()->getContext();
        $cx->table("app_users")->where([
            "app"  => $this->getId(),
            "user" => $user->getId(),
        ])->update([
            "access" => $permMask,
        ]);

        return true;
    }

    public function isEnabled(): bool
    {
        return (bool) $this->getRecord()->enabled;
    }

    public function enable(): void
    {
        $this->stateChanges("enabled", 1);
        $this->save();
    }

    public function disable(): void
    {
        $this->stateChanges("enabled", 0);
        $this->save();
    }

    public function install(User $user): void
    {
        if (!$this->getInstallationEntry($user)) {
            $cx = DatabaseConnection::i()->getContext();
            $cx->table("app_users")->insert([
                "app"  => $this->getId(),
                "user" => $user->getId(),
            ]);
        }
    }

    public function uninstall(User $user): void
    {
        $cx = DatabaseConnection::i()->getContext();
        $cx->table("app_users")->where([
            "app"  => $this->getId(),
            "user" => $user->getId(),
        ])->delete();
    }

    public function addCoins(float $coins): float
    {
        $res = $this->getBalance() + $coins;
        $this->stateChanges("coins", $res);
        $this->save();

        return $res;
    }

    public function withdrawCoins(): void
    {
        $balance = $this->getBalance();
        $tax     = ($balance / 100) * OPENVK_ROOT_CONF["openvk"]["preferences"]["apps"]["withdrawTax"];

        $owner = $this->getOwner();
        $owner->setCoins($owner->getCoins() + ($balance - $tax));
        $this->setCoins(0.0);
        $this->save();
        $owner->save();
    }

    public function delete(bool $softly = true): void
    {
        if ($softly) {
            throw new \UnexpectedValueException("Can't delete apps softly.");
        } // why

        $cx = DatabaseConnection::i()->getContext();
        $cx->table("app_users")->where("app", $this->getId())->delete();

        parent::delete(false);
    }

    public function getPublicationTime(): string
    {
        return tr("recently");
    }
}
