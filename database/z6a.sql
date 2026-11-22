CREATE TABLE IF NOT EXISTS musictype (
  idmt SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(80) NOT NULL,
  PRIMARY KEY (idmt),
  UNIQUE KEY musictype_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci;

INSERT IGNORE INTO musictype (idmt,name) VALUES
(1,'pop'),
(2,'rock'),
(3,'hip-hop'),
(4,'electronic dance'),
(5,'R&B'),
(6,'latin'),
(7,'country'),
(8,'metal'),
(9,'jazz'),
(10,'classical'),
(11,'other');

CREATE TABLE IF NOT EXISTS song (
  ids INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title TEXT NOT NULL,
  musician TEXT NOT NULL,
  datetime DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  idu SMALLINT UNSIGNED NOT NULL,
  filename VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  lyrics TEXT NOT NULL,
  idmt SMALLINT UNSIGNED NOT NULL,
  mime VARCHAR(40) NOT NULL,
  size BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (ids),
  UNIQUE KEY song_filename (filename),
  KEY song_owner (idu),
  CONSTRAINT z6a_media_owner FOREIGN KEY (idu) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT z6a_media_type FOREIGN KEY (idmt) REFERENCES musictype(idmt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci;

CREATE TABLE IF NOT EXISTS playlistname (
  idpl INT UNSIGNED NOT NULL AUTO_INCREMENT,
  idu SMALLINT UNSIGNED NOT NULL,
  name TEXT NOT NULL,
  public TINYINT UNSIGNED NOT NULL DEFAULT 0,
  datetime DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (idpl),
  KEY playlist_owner (idu),
  CONSTRAINT z6a_playlist_owner FOREIGN KEY (idu) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci;

CREATE TABLE IF NOT EXISTS playlistdatabase (
  idpldb INT UNSIGNED NOT NULL AUTO_INCREMENT,
  idpl INT UNSIGNED NOT NULL,
  ids INT UNSIGNED NOT NULL,
  position INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (idpldb),
  UNIQUE KEY playlist_media (idpl,ids),
  KEY playlist_order (idpl,position,idpldb),
  CONSTRAINT z6a_entry_playlist FOREIGN KEY (idpl) REFERENCES playlistname(idpl) ON DELETE CASCADE,
  CONSTRAINT z6a_entry_media FOREIGN KEY (ids) REFERENCES song(ids) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci;
