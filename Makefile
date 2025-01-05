
up:
	./vendor/bin/sail up -d

down:
	./vendor/bin/sail down

migrate:
	./vendor/bin/sail artisan migrate

pint:
	./vendor/bin/pint

stan:
	./vendor/bin/phpstan analyse app tests Modules --memory-limit=512M

cc:
	./vendor/bin/sail artisan optimize

cache:
	./vendor/bin/sail artisan cache:clear

test:
	./vendor/bin/sail artisan test

shell:
	./vendor/bin/sail shell

swagger:
	./vendor/bin/sail artisan l5-swagger:generate
