<?php

declare(strict_types=1);

namespace SharepointGraphClient;

class GraphUser extends GraphObject
{
    /**
     * Graph Site
     */
    protected GraphSite $site;

    /**
     * Graph User ID
     */
    protected string $id = '';

    /**
     * User Account (user principal name)
     */
    protected string $account = '';

    /**
     * User Email
     */
    protected string $email = '';

    /**
     * User Full Name
     */
    protected string $fullName = '';

    /**
     * User First Name
     */
    protected string $firstName = '';

    /**
     * User Last Name
     */
    protected string $lastName = '';

    /**
     * User Title (job title)
     */
    protected ?string $title = null;

    /**
     * Graph User constructor
     *
     * @param  string[] $json  JSON response from the Graph API
     * @param  string[] $extra Extra properties to map
     * @throws GraphException
     */
    public function __construct(GraphSite $site, array $json, array $extra = [])
    {
        parent::__construct([
            'id'        => 'id',
            'account'   => 'userPrincipalName',
            'email'     => 'mail',
            'fullName'  => 'displayName',
            'firstName' => 'givenName',
            'lastName'  => 'surname',
            'title'     => 'jobTitle',
        ], $extra);

        $this->site = $site;

        $this->hydrate($json);
    }

    /**
     * {@inheritdoc}
     */
    public function toArray(): array
    {
        return [
            'id'         => $this->id,
            'account'    => $this->account,
            'email'      => $this->email,
            'full_name'  => $this->fullName,
            'first_name' => $this->firstName,
            'last_name'  => $this->lastName,
            'title'      => $this->title,
            'extra'      => $this->extra,
        ];
    }

    /**
     * Get the Graph User ID
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Get the Graph Site
     */
    public function getGraphSite(): GraphSite
    {
        return $this->site;
    }

    /**
     * Get the Graph User Account (user principal name)
     */
    public function getAccount(): string
    {
        return $this->account;
    }

    /**
     * Get the Graph User Email
     */
    public function getEmail(): string
    {
        return $this->email;
    }

    /**
     * Get the Graph User Full Name
     */
    public function getFullName(): string
    {
        return $this->fullName;
    }

    /**
     * Get the Graph User First Name
     */
    public function getFirstName(): string
    {
        return $this->firstName;
    }

    /**
     * Get the Graph User Last Name
     */
    public function getLastName(): string
    {
        return $this->lastName;
    }

    /**
     * Get the Graph User Title (job title)
     */
    public function getTitle(): ?string
    {
        return $this->title;
    }

    /**
     * Get the Graph User photo
     *
     * @param  string|null $size Photo size (eg. '48x48', '96x96', '648x648')
     * @throws GraphException
     */
    public function getPhoto(?string $size = null): ?string
    {
        $path = sprintf('users/%s/photo', rawurlencode($this->id));

        if ($size !== null) {
            $path = sprintf('users/%s/photos/%s', rawurlencode($this->id), rawurlencode($size));
        }

        try {
            $response = $this->site->request($path.'/$value', [], 'GET', false);
        } catch (GraphException $e) {
            // 404 when the User has no photo
            if ($e->getCode() === 404) {
                return null;
            }

            throw $e;
        }

        return (string) $response->getBody();
    }

    /**
     * Get the current (delegated) Graph User
     *
     * Requires a delegated Access Token (app-only
     * Access Tokens cannot address /me).
     *
     * @static
     * @throws GraphException
     */
    public static function getCurrent(GraphSite $site, array $extra = []): static
    {
        $json = $site->request('me');

        return new static($site, $json, $extra);
    }

    /**
     * Get a Graph User by Account (user principal name) or ID
     *
     * @static
     * @throws GraphException
     */
    public static function getByAccount(GraphSite $site, string $account, array $extra = []): static
    {
        $json = $site->request('users/'.rawurlencode($account));

        return new static($site, $json, $extra);
    }

    /**
     * Get a Graph User by Email
     *
     * Returns the first matching Graph User, or null.
     *
     * @static
     * @throws GraphException
     */
    public static function getByEmail(GraphSite $site, string $email, array $extra = []): ?static
    {
        $json = $site->request('users', [
            'query' => [
                '$filter' => sprintf("mail eq '%s'", str_replace("'", "''", $email)),
                '$top'    => 1,
            ],
        ]);

        $value = $json['value'][0] ?? null;

        return $value !== null ? new static($site, $value, $extra) : null;
    }

    /**
     * Get all Graph Users of the Tenant
     *
     * @static
     * @throws GraphException
     * @return GraphUser[]
     */
    public static function getAll(GraphSite $site, array $settings = []): array
    {
        $items = $site->getAllPages('users', [
            'query' => [
                '$top' => $settings['top'] ?? 100,
            ],
        ]);

        $users = [];

        foreach ($items as $user) {
            $users[$user['id']] = new static($site, $user, $settings['extra'] ?? []);
        }

        return $users;
    }
}
