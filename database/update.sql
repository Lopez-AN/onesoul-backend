CREATE INDEX idx_offerings_status_approved
ON Offerings (Status, Approved, OfferingID);

CREATE INDEX idx_reviews_offering ON Reviews (OfferingID);
CREATE INDEX idx_reviews_seeker   ON Reviews (SeekerID);

CREATE INDEX idx_bookings_offering_event
ON Bookings (OfferingID, LastBookingEvent);

CREATE INDEX idx_media_offering_type
ON Media (OfferingID, MediaType);

DROP TABLE `OfferingsPackages`;

ALTER TABLE `Bookings`
	DROP FOREIGN KEY `Bookings_ibfk_3`;
