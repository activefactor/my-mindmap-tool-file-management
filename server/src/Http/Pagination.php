<?php

declare(strict_types=1);

namespace App\Http;

/**
 * 一覧系エンドポイントのページネーション（基本設計書_Phase2.md §5.2）。
 *
 * 管理コンソールの一覧はページ番号方式とする（総件数を表示したい／任意のページへ
 * 飛びたい画面のため。カーソル方式はマップ一覧など件数が伸びる箇所で検討する）。
 */
final class Pagination
{
    public const DEFAULT_PER_PAGE = 50;
    public const MAX_PER_PAGE = 200;

    private function __construct(
        public readonly int $page,
        public readonly int $perPage,
    ) {
    }

    /** @param array<string, mixed> $query */
    public static function fromQuery(array $query): self
    {
        $page = self::positiveInt($query['page'] ?? null, 1);
        $perPage = self::positiveInt($query['per_page'] ?? null, self::DEFAULT_PER_PAGE);

        return new self($page, min($perPage, self::MAX_PER_PAGE));
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    /** @return array<string, int> */
    public function meta(int $total): array
    {
        return [
            'page' => $this->page,
            'per_page' => $this->perPage,
            'total' => $total,
            'total_pages' => $total === 0 ? 0 : (int) ceil($total / $this->perPage),
        ];
    }

    private static function positiveInt(mixed $value, int $default): int
    {
        if (!is_string($value) && !is_int($value)) {
            return $default;
        }

        $int = filter_var($value, FILTER_VALIDATE_INT);

        return $int === false || $int < 1 ? $default : $int;
    }
}
