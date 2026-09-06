<?php
/**
 * The Vendor Agreement itself — the words, and where the blanks are.
 *
 * This file is the club's paper contract transcribed, and it is deliberately
 * ONLY that. No storage, no validation, no permissions: those live in
 * contracts.php, so that amending the agreement is an edit to this one file and
 * a reader comparing it against the paper never has to step over plumbing.
 *
 * TRANSCRIBED FROM: "GAFS Vendor Form.pdf", four pages, undated. That PDF is
 * flattened artwork with no text layer, so this was read off the page rather
 * than extracted -- which is exactly why the club must proofread it against
 * the paper before it is put in front of a vendor. Wording, capitalisation, and
 * the odd double space are reproduced as they appear, including the original's
 * "$2,000.000" and "neat and undamaged is all respects", because a transcription
 * that silently improves the source is no longer a transcription.
 *
 * ONE DELIBERATE DEPARTURE from the PDF, on the club's instruction: the society's
 * name is hyphenated here -- "German-American Society" -- where the PDF has it
 * open throughout. It is the organisation's own name in its own contract, and
 * the club is authoritative on how it is spelled. Recorded here because a reader
 * diffing this against the paper will find it, and an unexplained difference in
 * a contract is indistinguishable from drift.
 *
 * WHEN THIS TEXT CHANGES, CHANGE THE VERSION in Email CRM -> Settings. Every
 * acceptance stores both the version and a full snapshot of what was on screen,
 * so past agreements keep saying what their signatories actually agreed to --
 * but the version is what a person reads, and a stale one is a lie about a
 * contract.
 *
 * Blanks are declared by key through gasf_crm_vendor_blank(). Fields marked
 * 'club' are the Society's to complete after accepting a vendor; on the public
 * form they render as an uneditable note rather than an input, because a fee or
 * a deposit is not something a stranger fills in about themselves.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Render the agreement.
 *
 * @param string $mode   'form' for the public, fillable page; 'record' to show
 *                       one back as it was signed.
 * @param array  $values Field values, keyed as below. Ignored in 'form' mode
 *                       except to repopulate a rejected submission.
 * @param array  $locked Blanks the organiser fixed in advance. Printed as
 *                       values in both modes, never as fields. Defaults to
 *                       whatever settings say, so a caller that does not care
 *                       still renders the right document.
 */
function gasf_crm_vendor_contract( $mode = 'form', array $values = array(), $locked = null ) {
	if ( ! is_array( $locked ) ) { $locked = gasf_crm_vendor_locked_values(); }
	gasf_crm_vendor_ctx( array( 'mode' => $mode, 'values' => $values, 'locked' => $locked ) );
	$b = 'gasf_crm_vendor_blank';
	?>
<div class="gv-contract">

	<header class="gv-head">
		<p class="gv-org">GERMAN-AMERICAN SOCIETY &ldquo;FRIENDSHIP&rdquo; OF PINELLAS COUNTY INC</p>
		<p class="gv-org">8098 66<sup>th</sup> STREET N. &nbsp;PINELLAS PARK FL &nbsp;33781</p>
		<p class="gv-title">VENDOR AGREEMENT</p>
	</header>

	<p>Thank you for your interest in providing products/services/entertainment for an event held at
		<strong>German-American Society</strong> premises or on their behalf.</p>

	<p>This agreement, made this <?php $b( 'agr_day', array( 'w' => 'xs', 'aria' => 'Day of the month' ) ); ?>
		day of <?php $b( 'agr_month', array( 'w' => 'sm', 'aria' => 'Month' ) ); ?>
		202<?php $b( 'agr_year', array( 'w' => 'xs', 'aria' => 'Year, last digit' ) ); ?>
		by and between the German-American Society Friendship of Pinellas County Inc., referred to herein as the
		<strong>German-American Society</strong> and <?php $b( 'vendor_legal', array( 'w' => 'xl', 'aria' => 'Vendor legal name' ) ); ?>
		herein after referred to as the <strong>Vendor.</strong></p>

	<dl class="gv-rows">
		<dt>Date of Event:</dt>
		<dd><?php $b( 'event_date', array( 'w' => 'md', 'aria' => 'Date of event' ) ); ?></dd>

		<dt>Type/Name of Event:</dt>
		<dd><?php $b( 'event_name', array( 'w' => 'xl', 'aria' => 'Type or name of event' ) ); ?></dd>

		<dt>Vendor Address:</dt>
		<dd><?php $b( 'vendor_address', array( 'w' => 'xl', 'aria' => 'Vendor address' ) ); ?></dd>

		<dt>City:</dt>
		<dd>
			<?php $b( 'vendor_city', array( 'w' => 'lg', 'aria' => 'City' ) ); ?>
			<span class="gv-inline">State:</span> <?php $b( 'vendor_state', array( 'w' => 'sm', 'aria' => 'State' ) ); ?>
			<span class="gv-inline">Zip:</span> <?php $b( 'vendor_zip', array( 'w' => 'sm', 'aria' => 'ZIP code' ) ); ?>
		</dd>

		<dt><strong>Vendor</strong> Primary Point of Contact (POC) Name:</dt>
		<dd><?php $b( 'poc_name', array( 'w' => 'xl', 'aria' => 'Primary point of contact name' ) ); ?></dd>

		<dt><strong>Vendor</strong> POC Mobile #:</dt>
		<dd><?php $b( 'poc_mobile', array( 'w' => 'lg', 'type' => 'tel', 'aria' => 'Point of contact mobile number' ) ); ?></dd>

		<dt><strong>Vendor</strong> POC Email:</dt>
		<dd><?php $b( 'poc_email', array( 'w' => 'lg', 'type' => 'email', 'aria' => 'Point of contact email' ) ); ?></dd>
	</dl>

	<p><strong>Vendor</strong> agrees to provide the following specifically-described products and/or services,
		agreeing that any products and/or services not specifically included will be excluded from the Agreement
		and the <strong>Vendor</strong> accepts all responsibility for any loss, cost, claim, expense or liability
		associated therewith:</p>

	<p class="gv-eg">e.g. &ndash; Vendor wishes to sell Bavarian Brats and Pretzels to the event attendees for profit</p>

	<?php
	/*
	 * One blank, filled from the application above rather than asked again.
	 *
	 * The paper has three ruled lines here and a vendor writes across them. On
	 * screen that would mean asking for the same description twice -- once to
	 * decide whether to accept them, once inside the contract -- and two
	 * descriptions in one signed document is a dispute waiting to happen. The
	 * answer given above is what lands here, and it is what they sign.
	 */
	?>
	<p class="gv-lines">
		<?php $b( 'desc_full', array( 'w' => 'full', 'auto' => 'Taken from the description of your goods above.', 'aria' => 'Description of products or services' ) ); ?>
	</p>

	<p>The <strong>German-American Society</strong> in consideration of the fees and agreements mentioned here,
		agrees to allow the vendor access to and use of the premises situated in City of Pinellas Park, Pinellas
		County, FL as described above to the <strong>Vendor</strong> for the sum of
		$<?php $b( 'fee_amount', array( 'w' => 'md', 'club' => true, 'aria' => 'Fee' ) ); ?> (see Vendor Addendum).</p>

	<?php
	/*
	 * The paper's DEPOSIT RECEIVED / Balance owed / OTHER MONIES block is NOT
	 * here, deliberately.
	 *
	 * Those lines are the treasurer's running record of what has been paid, and
	 * they are filled in over weeks, after the vendor has signed and gone away.
	 * On a page a stranger is reading they were four rows of grey boxes that
	 * could never be filled and explained nothing. They live in the Contracts
	 * pane instead, against the agreement they belong to, where somebody can
	 * actually keep them up to date -- and where editing them cannot touch the
	 * signed document.
	 */
	?>
	<h3>TERMS</h3>
	<p><strong>Vendor</strong> agrees to pay half the fee made payable to the <strong>German-American Society,</strong>
		upon signing of this contract and the balance due plus damage and cleanup fee at least 30 days prior to the
		event. The use of any equipment that belongs to the <strong>German-American Society</strong> is not included
		unless specified. <strong>Vendors</strong> to profit making ventures are subject to the
		<strong>German-American Society</strong> approval. The <strong>German-American Society</strong> reserves the
		right to decline a <strong>Vendor</strong>.</p>

	<p>The <strong>Vendor</strong> agrees to leave the premises neat and undamaged is all respects after event
		(all trash cans must be emptied and taken to the outside dumpster).</p>

	<h3 class="gv-ul">CANCELLATION POLICY</h3>
	<p>Advance deposits are refundable less a cancellation fee of $50.00, if the
		<strong>German-American Society</strong> is notified of cancellation in writing at least 45 days prior to
		the day of the event. Refunds will be issued within 10 days after notification of cancellation. Deposits
		are non-refundable if event is canceled less than 45 days before the event.</p>

	<p>If an event is canceled by the <strong>German-American Society,</strong> the full deposit will be refunded
		within 10 days after the cancellation. The <strong>German-American Society</strong> shall not be liable for
		damage of any type, whether direct or consequential, to the <strong>Vendor,</strong> for cancellation of the
		event. The <strong>Vendor</strong> acknowledges and understands that the sole remedy for any claim of
		damages arising out of, or relating to, a cancellation shall be a refund of deposits.</p>

	<p>The <strong>Vendor</strong> agrees that if prior to or during the term of this event, these premises should
		be destroyed or rendered unfit for the purposed use by the <strong>Vendor,</strong> by any cause beyond the
		control of the <strong>German-American Society,</strong> this shall cancel and any amounts paid by the
		<strong>Vendor</strong> shall be refunded.</p>

	<h3 class="gv-ul">LIABILITY</h3>
	<p>If <strong>Vendor</strong> supplies food and service, the <strong>Vendor</strong> is 100% responsible for the
		health and welfare of those attending the event. The <strong>German-American Society</strong> is not
		responsible for theft of any kind or items left on the property either prior to or at the end of an event.
		The <strong>German-American Society</strong> is not liable for any injury or damage to any person, or to any
		property at any time on or around said premises from any cause whatsoever that may at any time exist from
		the use or condition of said premises.</p>

	<p>The <strong>German-American Society</strong> is not responsible for damages to or loss of personal property
		of the <strong>Vendor,</strong> which is left on the premises before, during or after the event. The
		<strong>German-American Society</strong> is further indemnified and held harmless by the
		<strong>Vendor</strong> for any damages due to the actions, products, services provided by the
		<strong>Vendor</strong> or occupancy of the <strong>Vendor.</strong></p>

	<p>It is further agreed that the <strong>Vendor</strong> will not assign this agreement nor sublet any part of
		premises without written consent; that the <strong>Vendor</strong> will properly repair and replace all
		breakages, defacements and damages, except damages caused by the elements, in a manner acceptable to
		<strong>German-American Society</strong> and subject to written approval by
		<strong>German-American Society</strong>; that the <strong>Vendor</strong> will at all times admit said
		<strong>German-American Society</strong> and its agents upon said premises to inspect, maintain and repair
		the same; that at the termination of the Agreement, and at any time <strong>Vendor</strong> fails to keep
		any of the covenants herein contained during the event, <strong>Vendor</strong> will peacefully and quietly
		surrender to the <strong>German-American Society</strong> the possession of said premises upon demand of the
		<strong>German-American Society</strong> and that such re-entry by the
		<strong>German-American Society</strong> shall not operate to defeat the
		<strong>German-American Society's</strong> right to enforce the terms of this Agreement as to payment of
		fees or consideration and the specific performance thereof; that the <strong>Vendor</strong> will not use or
		permit anything which will increase the rate of insurance or which may be dangerous to life, or limb or
		permit these premises or any part thereof to be used in a manner contrary to the laws, ordinances or
		regulations of the United States, the State of Florida, County of Pinellas or City of Pinellas Park.</p>

	<h3 class="gv-ul">INSURANCE</h3>
	<p>The <strong>Vendor</strong> must have General Liability, including Damage to Premises Rented To You, and
		Liquor Liability (if applicable) Insurance, with the <strong>German-American Society</strong> named as
		Additional Insured, in the amount of no less than $1,000,000 per occurrence / $2,000.000 aggregate. This
		policy must be Primary and Non-contributory, include Waiver of Subrogation in favor of German American
		Society, and contain no exclusion for Cross Liability suits. Proof of insurance must be presented at least
		thirty (30) days prior to event.</p>

	<?php if ( 'form' === $mode ) : ?>
		<p class="gv-note">You can attach your certificate of insurance at the bottom of this form, after the
			signature. If you do not have it yet, send it to us when it arrives &mdash; but no vendor sets up
			without it.</p>
	<?php endif; ?>

	<h3 class="gv-ul">INDEMNIFICATION</h3>
	<p>The <strong>Vendor</strong> shall indemnify, defend, and hold harmless
		<strong>German-American Society</strong> from and against any and all liabilities, losses, claims, demands,
		and actions that may arise from the providing/selling of products or services, use of the premises or a
		breach of this Agreement, including reasonable attorneys' fees, costs, and expenses, to the extent caused
		by the acts or omissions of <strong>Vendor,</strong> its guests, sub-contractors, suppliers, agents, or
		anyone employed directly or indirectly by any of them or by anyone for whose acts any of them may be
		liable.</p>

	<?php
	/*
	 * RECEIPT OF PROOF OF INSURANCE and ADDENDA ATTACHED are NOT here.
	 *
	 * Both are the Society's record of what it has received and enclosed, filled
	 * in by whoever takes the certificate off the vendor -- often weeks after
	 * this page was signed. On the vendor's copy they were four grey boxes that
	 * could never be filled and that invited exactly the question "am I supposed
	 * to do this?". They are in the Contracts pane, against the submission they
	 * belong to, where somebody can actually tick them.
	 */
	?>

	<p class="gv-attest"><strong>I HEREBY AGREE TO THE CONDITIONS FOR VENDOR USE OF PREMISES AND SIGNIFY THAT ALL
		INFORMATION SUPPLIED BY ME IS TRUE AND CORRECT. I ASSUME ALL LIABILITY FOR THE CONDUCT OF MYSELF AND OTHERS
		ASSOCIATED WITH ME OR MY BUSINESS AND FOR DAMAGE INCURRED WHILE PROVIDING AND SELLING PRODUCTS AND SERVICES
		AT THE DESCRIBED EVENT.</strong></p>

	<?php if ( 'form' === $mode ) : ?>
		<p class="gv-note">Typing your name below is your signature on this agreement. We record your name, the
			date, and the version of this agreement you signed.</p>
	<?php endif; ?>

	<dl class="gv-rows gv-sign">
		<dt>Signature of <strong>Vendor</strong> POC:</dt>
		<dd><?php $b( 'sign_vendor', array( 'w' => 'lg', 'sig' => true, 'aria' => 'Signature of vendor point of contact' ) ); ?>
			Date: <?php $b( 'sign_vendor_date', array( 'w' => 'md', 'aria' => 'Signature date' ) ); ?></dd>

		<dt>Co-Signer Signature:</dt>
		<dd><?php $b( 'sign_cosigner', array( 'w' => 'lg', 'sig' => true, 'aria' => 'Co-signer signature' ) ); ?>
			Date: <?php $b( 'sign_cosigner_date', array( 'w' => 'md', 'aria' => 'Co-signer date' ) ); ?></dd>

		<dt>(Tax Exempt#)</dt>
		<dd><?php $b( 'tax_exempt', array( 'w' => 'md', 'aria' => 'Tax exempt number' ) ); ?> (if applicable)</dd>

	</dl>

	<?php
	/*
	 * The Society's countersignature is STATED here, not drawn as blanks.
	 *
	 * Two reasons, and the second is the one that decides it. A vendor cannot
	 * fill these in, so three grey boxes on their screen only invite the
	 * question "am I supposed to know this?". And the countersignature happens
	 * AFTER they sign -- an officer signs once the application has been read --
	 * so it could never appear in the copy taken at the moment they submit,
	 * whatever it looked like. Empty boxes would have been permanently empty.
	 *
	 * What a vendor does need to know is that this is an agreement rather than
	 * a one-sided undertaking, and that it is not in force until somebody signs
	 * back. That is a sentence, so it is written as one. The officer's name,
	 * signature, and date are recorded against the application in the Contracts
	 * pane, where the person who actually signs can enter them.
	 */
	?>
	<p class="gv-countersign"><strong>To be countersigned by the German-American Society.</strong>
		An officer of the Society signs this agreement after reviewing your application. It does not
		come into force until they do, and we will let you know when it has been signed.</p>
</div>
	<?php
}
