-- Databases for the test suite, created by initdb on every start of the `test-database` service
-- (its data directory is a tmpfs, so it is empty again after every restart).
--
-- `app_test` is what a single-process `php bin/phpunit` uses: Doctrine appends the `_test` suffix
-- configured in config/packages/doctrine.yaml. The numbered databases belong to parallel runs, where
-- the same setting appends TEST_TOKEN as well, so worker N is isolated in `app_test<N>`. Sixteen
-- covers any core count this suite is run on; bin/ptest creates any that are still missing, so
-- raising the worker count beyond that needs no change here.
CREATE DATABASE app_test;

CREATE DATABASE app_test1;
CREATE DATABASE app_test2;
CREATE DATABASE app_test3;
CREATE DATABASE app_test4;
CREATE DATABASE app_test5;
CREATE DATABASE app_test6;
CREATE DATABASE app_test7;
CREATE DATABASE app_test8;
CREATE DATABASE app_test9;
CREATE DATABASE app_test10;
CREATE DATABASE app_test11;
CREATE DATABASE app_test12;
CREATE DATABASE app_test13;
CREATE DATABASE app_test14;
CREATE DATABASE app_test15;
CREATE DATABASE app_test16;
