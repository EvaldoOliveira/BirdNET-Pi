#!/usr/bin/env bash
source /etc/birdnet/birdnet.conf
sqlite3 $HOME/BirdNET-Pi/scripts/birds.db << EOF
DROP TABLE IF EXISTS detections;
CREATE TABLE IF NOT EXISTS detections (
  Date DATE,
  Time TIME,
  Sci_Name VARCHAR(100) NOT NULL,
  Com_Name VARCHAR(100) NOT NULL,
  Confidence FLOAT,
  Lat FLOAT,
  Lon FLOAT,
  Cutoff FLOAT,
  Week INT,
  Sens FLOAT,
  Overlap FLOAT,
  File_Name VARCHAR(100) NOT NULL);
CREATE INDEX "detections_Com_Name" ON "detections" ("Com_Name");
CREATE INDEX "detections_Sci_Name" ON "detections" ("Sci_Name");
CREATE INDEX "detections_Date_Time" ON "detections" ("Date" DESC, "Time" DESC);
CREATE TABLE IF NOT EXISTS detection_reviews (
  File_Name VARCHAR(100) PRIMARY KEY,
  Sci_Name VARCHAR(100),
  Com_Name VARCHAR(100),
  Date DATE,
  Confidence FLOAT,
  Verdict TEXT NOT NULL CHECK (Verdict IN ('yes','no','unsure')),
  Reviewed_At TEXT,
  Reason TEXT);
CREATE TABLE IF NOT EXISTS deleted_detections (Date DATE, Time TIME, Sci_Name VARCHAR(100) NOT NULL, Com_Name VARCHAR(100) NOT NULL,
  Confidence FLOAT, Lat FLOAT, Lon FLOAT, Cutoff FLOAT, Week INT, Sens FLOAT, Overlap FLOAT, File_Name VARCHAR(100) NOT NULL, Deleted_At TEXT);
EOF
chown $USER:$USER $HOME/BirdNET-Pi/scripts/birds.db
chmod g+w $HOME/BirdNET-Pi/scripts/birds.db
