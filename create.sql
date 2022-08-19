CREATE TABLE `ticker`
(
    `filter` varchar(45) NOT NULL,
    `text`   varchar(5)  NOT NULL,
    PRIMARY KEY (`filter`)
) DEFAULT CHARSET = UTF8MB4;

CREATE TABLE `user`
(
    `character_id`     int         NOT NULL,
    `character_name`   varchar(45) NOT NULL,
    `corporation_id`   int         NOT NULL,
    `corporation_name` varchar(45) NOT NULL,
    `alliance_id`      int         DEFAULT NULL,
    `alliance_name`    varchar(45) DEFAULT NULL,
    `mumble_username`  varchar(45) NOT NULL,
    `mumble_password`  varchar(45) NOT NULL,
    `created_at`       int         NOT NULL,
    `updated_at`       int         NOT NULL,
    `groups`           longtext,
    `owner_hash`       varchar(45) NOT NULL,
    PRIMARY KEY (`character_id`)
) DEFAULT CHARSET = UTF8MB4;

CREATE TABLE `ban`
(
    `filter`          varchar(45) NOT NULL,
    `reason_public`   longtext,
    `reason_internal` longtext,
    PRIMARY KEY (`filter`)
) DEFAULT CHARSET = UTF8MB4;
