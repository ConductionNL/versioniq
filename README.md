<p align="center">
  <img src="img/app-store.svg" alt="Versioniq logo" width="80" height="80">
</p>

# Versioniq

A template to get started with Nextcloud app development.

## Test coverage

<!-- coverage:start -->
Measured test coverage: 93.2% of statements (PHP 93.2% of 5,606 statements), at commit `2281797` on 2026-11-12. The JavaScript frontend is not in this figure, because it has no coverage measurement yet. The figures come from [`coverage-summary.json`](coverage-summary.json); refresh them with `composer coverage:publish`.
<!-- coverage:end -->

## Usage

- To get started easily use the [Appstore App generator](https://apps.nextcloud.com/developer/apps/generate) to
  dynamically generate an App based on this repository with all the constants prefilled.
- Alternatively you can use the "Use this template" button on the top of this page to create a new repository based on
  this repository. Afterwards adjust all the necessary constants like App ID, namespace, descriptions etc.

Once your app is ready follow the [instructions](https://nextcloudappstore.readthedocs.io/en/latest/developer.html) to
upload it to the Appstore.

## Resources

### Documentation for developers:

- General documentation and tutorials: https://nextcloud.com/developer
- Technical documentation: https://docs.nextcloud.com/server/latest/developer_manual

### Help for developers:

- Official community chat: https://cloud.nextcloud.com/call/xs25tz5y
- Official community forum: https://help.nextcloud.com/c/dev/11

## License

Copyright (C) 2024-2026 Conduction B.V.

This project is licensed under the [EUPL-1.2](LICENSE).

## Deployment

### Try it with Docker

The repository builds an evaluation image: the official Nextcloud image with versioniq and OpenRegister installed and enabled. It is meant for trying the app out and for test environments. For production, install the app from the Nextcloud App Store.

```bash
docker compose -f docker-compose.yml up -d --build
```

Open http://localhost:8080/apps/versioniq/ and sign in as `admin` with password `admin`. The first start takes a minute or two. Set `NEXTCLOUD_ADMIN_PASSWORD` and `APP_PORT` to change the password and the port; `docker-compose.yml` lists every setting. `docker compose -f docker-compose.yml down -v` removes the stack and its data.

Released versions are published as `ghcr.io/conductionnl/versioniq`, so leaving out `--build` uses the published image.

### Run it on Kubernetes

`charts/versioniq` wraps the official [Nextcloud Helm chart](https://github.com/nextcloud/helm) and runs the same image with PostgreSQL:

```bash
helm dependency build charts/versioniq
helm install versioniq charts/versioniq --set nextcloud.nextcloud.host=versioniq.example.org
```

Change the passwords in `charts/versioniq/values.yaml` before you install it anywhere that matters.
