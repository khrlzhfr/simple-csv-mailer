/* Simple CSV Mailer: reloads the sending screen to send the next batch. */
( function () {
	var seconds = ( window.scmSending && window.scmSending.seconds ) || 3;
	setTimeout( function () {
		location.reload();
	}, seconds * 1000 );
} )();
