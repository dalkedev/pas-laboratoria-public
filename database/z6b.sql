CREATE TABLE IF NOT EXISTS filmtype (
  idft SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(80) NOT NULL,
  PRIMARY KEY (idft),
  UNIQUE KEY filmtype_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci;

INSERT IGNORE INTO filmtype (idft,name) VALUES
(1,'documentary'),
(2,'reportage'),
(3,'current affairs'),
(4,'action'),
(5,'sci-fi'),
(6,'horror'),
(7,'family'),
(8,'nature'),
(9,'concert'),
(10,'animation'),
(11,'other');

CREATE TABLE IF NOT EXISTS film (
  idf INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title TEXT NOT NULL,
  director TEXT NOT NULL,
  datetime DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  idu SMALLINT UNSIGNED NOT NULL,
  filename VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  subtitles TEXT NOT NULL,
  idft SMALLINT UNSIGNED NOT NULL,
  mime VARCHAR(40) NOT NULL,
  size BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (idf),
  UNIQUE KEY film_filename (filename),
  KEY film_owner (idu),
  CONSTRAINT z6b_media_owner FOREIGN KEY (idu) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT z6b_media_type FOREIGN KEY (idft) REFERENCES filmtype(idft)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci;

CREATE TABLE IF NOT EXISTS playlistname (
  idpl INT UNSIGNED NOT NULL AUTO_INCREMENT,
  idu SMALLINT UNSIGNED NOT NULL,
  name TEXT NOT NULL,
  public TINYINT UNSIGNED NOT NULL DEFAULT 0,
  datetime DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (idpl),
  KEY playlist_owner (idu),
  CONSTRAINT z6b_playlist_owner FOREIGN KEY (idu) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci;

CREATE TABLE IF NOT EXISTS playlistdatabase (
  idpldb INT UNSIGNED NOT NULL AUTO_INCREMENT,
  idpl INT UNSIGNED NOT NULL,
  idf INT UNSIGNED NOT NULL,
  position INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (idpldb),
  UNIQUE KEY playlist_media (idpl,idf),
  KEY playlist_order (idpl,position,idpldb),
  CONSTRAINT z6b_entry_playlist FOREIGN KEY (idpl) REFERENCES playlistname(idpl) ON DELETE CASCADE,
  CONSTRAINT z6b_entry_media FOREIGN KEY (idf) REFERENCES film(idf) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci;
