<?php
/**
 * Copyright © MageMe. All rights reserved.
 * See LICENSE for license terms, or https://mageme.com/license.
 */
declare(strict_types=1);

namespace MageMe\EUWithdrawal\Model\Security;

class UniformResponse
{
    /**
     * Constructor.
     *
     * @param string $redirectPath
     * @param array $queryParams
     */
    private function __construct(
        private readonly string $redirectPath,
        /** @var array<string, string> */
        private readonly array $queryParams,
    ) {
    }

    /**
     * Uniform.
     *
     * @return self
     */
    public static function uniform(): self
    {
        return new self(
            'withdraw-contract',
            ['status' => 'check-email'],
        );
    }

    /**
     * Redirect.
     *
     * @param string $path
     * @param array $params
     * @return self
     */
    public static function redirect(string $path, array $params = []): self
    {
        return new self($path, $params);
    }

    /**
     * Redirect path.
     *
     * @return string
     */
    public function redirectPath(): string
    {
        return $this->redirectPath;
    }

    /**
     * @return array<string, string>
     */
    public function queryParams(): array
    {
        return $this->queryParams;
    }
}
