#
# Add SQL definition of database tables
#
CREATE TABLE tx_localizer_settings_l10n_exportdata_mm
(
    identifier       varchar(32)   DEFAULT ''  NOT NULL,
    supertextid      varchar(64)   DEFAULT ''  NOT NULL,
    KEY identifier (identifier),
    KEY supertextid (supertextid),
);
