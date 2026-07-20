<?php
/**
 * CategorySemanticSections — category-specific sentence and heading frames.
 *
 * Replaces the seed-selected boilerplate from category-universal-sections.json
 * as the PRIMARY source of paragraph meaning. Each frame references the
 * semantic profile (subject / descriptor / format), so the produced sentence
 * states something true and specific about THIS category instead of generic
 * browsing advice. Frames still expose {{kw1}}/{{kw2}} slots so active Rank
 * Math phrases land inside category-specific sentences.
 *
 * Pure and category-agnostic: frames are chosen by the profile's subject_class
 * and by section purpose, never by category name/slug/keyword. intent+seed
 * pick among phrasings for variety only — they never choose the meaning.
 *
 * @package TMWSEO\Engine\Content\CategoryPipeline
 */

declare(strict_types=1);

namespace TMWSEO\Engine\Content\CategoryPipeline;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class CategorySemanticSections {

	/**
	 * Ordered category-specific sentence templates for a section purpose.
	 *
	 * @param string $section Section purpose id (intro, expectations, …).
	 * @param array  $profile CategorySemanticProfile::build() output.
	 * @param int    $seed    Structural-variety seed (phrasing only).
	 * @return string[] Templates with {{token}} slots; empty if none apply.
	 */
	/**
	 * @return array<int,array<int,string>> Ordered sentence-slots; each slot is
	 *         a list of interchangeable templates. The composer renders one
	 *         template per slot, so a section produces a multi-sentence
	 *         category-specific paragraph. Empty when no frame applies.
	 */
	public static function sentences( string $section, array $profile, int $seed = 0 ): array {
		$class = (string) ( $profile['subject_class'] ?? 'trait' );
		$bank  = self::bank( $section );
		if ( empty( $bank ) ) { return []; }

		$pool = $bank[ $class ] ?? $bank['default'] ?? [];
		if ( empty( $pool ) ) { $pool = $bank['default'] ?? []; }
		if ( empty( $pool ) ) { return []; }

		$pool      = array_values( $pool );
		$pick      = self::pick_offset( $profile, $seed, count( $pool ) );
		$paragraph = $pool[ $pick ];
		$slots = [];
		foreach ( (array) $paragraph as $slot ) {
			$slots[] = is_array( $slot ) ? array_values( $slot ) : [ (string) $slot ];
		}
		return $slots;
	}

	/**
	 * The paragraph-variant index this section/profile/seed selects. Exposed so
	 * the composer can encode it in the variant id, letting the cross-page
	 * cooldown and uniqueness guard treat different categories' picks as
	 * distinct variants.
	 */
	public static function picked_index( string $section, array $profile, int $seed = 0 ): int {
		$class = (string) ( $profile['subject_class'] ?? 'trait' );
		$bank  = self::bank( $section );
		$pool  = $bank[ $class ] ?? $bank['default'] ?? [];
		if ( empty( $pool ) ) { $pool = $bank['default'] ?? []; }
		$n = count( $pool );
		return $n > 0 ? self::pick_offset( $profile, $seed, $n ) : 0;
	}

	/**
	 * Deterministic variant offset: the CATEGORY IDENTITY (cat_seed) combined
	 * with the section seed, so two categories of the same subject_class pick
	 * DIFFERENT structural variants — their bodies differ in shape, not just in
	 * the swapped subject word.
	 */
	private static function pick_offset( array $profile, int $seed, int $count ): int {
		if ( $count <= 0 ) { return 0; }
		$cat_seed = (int) ( $profile['cat_seed'] ?? 0 );
		return ( $cat_seed + $seed ) % $count;
	}

	/**
	 * A category-specific heading for a section purpose.
	 *
	 * @return string Heading text (already token-substituted-safe: contains
	 *                {{subject_title}} which the planner resolves), or '' to
	 *                let the caller fall back.
	 */
	public static function heading( string $section, array $profile, int $seed = 0 ): string {
		$class = (string) ( $profile['subject_class'] ?? 'trait' );
		$map   = self::heading_bank();
		$bank  = $map[ $section ] ?? [];
		if ( empty( $bank ) ) { return ''; }
		$pool = $bank[ $class ] ?? $bank['default'] ?? [];
		if ( empty( $pool ) ) { return ''; }
		$pool = array_values( $pool );
		// Category identity drives the choice so two same-class categories get
		// different heading phrasings, not the same shape with a swapped word.
		$cat_seed = (int) ( $profile['cat_seed'] ?? 0 );
		return (string) $pool[ ( $cat_seed + $seed ) % count( $pool ) ];
	}

	/**
	 * Section → subject_class → frames. {{subject}} lower-case theme noun,
	 * {{subject_title}} title-cased, {{descriptor}} a theme descriptor,
	 * {{format}} the delivery mode, plus the composer's existing tokens
	 * ({{primary_keyword}}, {{site_name}}, {{model_scale}}, {{video_scale}},
	 * {{related_1}}, {{*_link}}, {{kw1}}, {{kw2}}).
	 */
	private static function bank( string $section ): array {
		// Each section maps subject_class → list of PARAGRAPHS. A paragraph is
		// an ordered list of sentence-slots; a slot is either a string or a
		// list of interchangeable phrasings. Concept vocabulary (trait,
		// presentation, variety, format, live, recorded, individual, narrow,
		// refine, …) is woven in so intent-specificity is satisfied by genuine
		// category prose rather than filler. {{kw1}} slots host active phrases.
		$banks = [
			'intro' => [
				'trait' => [
					[
						[ '{{primary_keyword}} gathers the {{subject}} performers and clips on {{site_name}} into one place, so every listing below already shares the {{subject}} focus you searched for.', 'Everything filed under {{primary_keyword}} is here because it fits the {{subject}} theme, so the models and videos on this page start from that trait instead of making you filter for it.' ],
						[ 'The label is a broad starting point, not a promise about presentation: it is a coarse anchor that narrows the wide directory down to this one {{subject}} look, leaving each performer to interpret the style individually.', 'Treat the trait as a broad anchor that narrows the whole wide field to this {{subject}} look, then refine by style and presentation toward a single match.' ],
						'A quick scan of {{kw1}} listings shows how much the individual pages vary in style and presentation once this broad starting point has done its part.',
						'{{intent_clarity_1}}',
					],
					[
						[ 'Start here when the {{subject}} look is what you are after: {{primary_keyword}} pulls every matching performer and clip on {{site_name}} onto one page so the field is already narrowed before you begin.', 'This page collects the {{subject}} side of {{site_name}} under {{primary_keyword}}, which means the wide directory is reduced to a single trait and you refine from there.' ],
						[ 'One shared look, many individual takes: the label groups the performers, and the style, presentation, and pace on each page are where they part ways.', 'The trait is the constant and everything visual around it varies, so the broad {{subject}} anchor gathers candidates while the specifics on each page separate them.' ],
						'Open {{kw1}} and the page it leads to shows how far two {{subject}} listings can differ once the shared trait has done its coarse sorting.',
						'{{intent_clarity_1}}',
					],
					[
						[ '{{primary_keyword}} is the {{subject}} entry point on {{site_name}}: it narrows a wide catalogue to one look so you can compare within it rather than search across everything.', 'Think of {{primary_keyword}} as the {{subject}} filter already applied — the models and clips below share the trait, and the page exists to help you refine, not to make you sort.' ],
						[ 'The label fixes the {{subject}} look and leaves style, presentation, and delivery open, so expect real variety once you move past the shared trait.', 'A shared trait is a broad anchor, not a full description, so two {{subject}} pages can look quite different in everything the label does not pin down.' ],
						'Scanning {{kw1}} first gives a feel for how the {{subject}} field varies before you commit to any single page.',
						'{{intent_clarity_1}}',
					],
				],
				'access' => [
					[
						[ '{{primary_keyword}} collects the {{subject}} options on {{site_name}}, so the listings below are grouped by how you get in rather than by any single performer look.', 'Everything under {{primary_keyword}} shares one thing — the {{subject}} entry model — which is the broad anchor this page narrows the wide directory down to before you open anything.' ],
						'Free and paid viewing sit side by side in these listings, so the page is a broad starting point for judging cost and open access, one you refine as you go rather than a single tier.',
						'A look at {{kw1}} shows how the public and paid boundaries shift from one room to the next.',
					],
				],
				'format' => [
					[
						[ '{{primary_keyword}} brings the {{subject}} listings on {{site_name}} together, so the page is a broad anchor organised around how each session is delivered rather than one fixed trait.', 'This is the {{subject}} view of {{site_name}}: {{primary_keyword}} narrows the wide field by format so you can refine toward the delivery — live or recorded — that suits the visit.' ],
						'Live rooms and on-demand clips both appear here, and the format each listing uses shapes the timing and presence of the session.',
						'Scanning {{kw1}} makes the delivery differences clear before you commit to a room.',
					],
				],
			],
			'expectations' => [
				'trait' => [
					[
						[ 'Expect variety within the {{subject}} theme: the shared trait is the constant, while presentation, {{descriptor}} emphasis, and pace stay individual choices, so the look on one page differs from the next.', 'The {{subject}} label fixes one physical trait and leaves the rest open, so two listings can share the look and still differ widely in style, presentation, and how much each page states.' ],
						'That spread is the point — one broad look, many individual takes on style and presentation — so read each page for the specifics rather than expecting the label to describe the visual.',
						'Where a listing carries {{kw1}}, the wording and thumbnail on its own page show how that performer presents the trait.',
						'{{intent_clarity_2}}',
					],
				],
				'access' => [
					[
						[ 'Expect the {{subject}} promise to be defined by each destination, not by this page: the listings share an access model, but the exact free and paid boundaries live on the platform each one leads to.', 'What stays constant across {{primary_keyword}} is the entry model; what varies is the cost and where open viewing ends, so read the specifics on the page you land on.' ],
						'Public browsing, paid tiers, and private sessions can all sit behind one listing, so treat the label as a broad starting point for judging value rather than a fixed price.',
						'A listing marked {{kw1}} still sets its own terms once you follow it out.',
						'{{intent_clarity_2}}',
					],
				],
				'format' => [
					[
						[ 'Expect the {{subject}} listings to differ in delivery: some lean live, others recorded, and the format each uses shapes the presence and timing of the visit.', 'Delivery is the main variable here — a live room and an on-demand clip carry the same theme with a very different pace.' ],
						'Read each listing for whether it is a live session or a saved clip, since that format decides how you engage more than any other detail.',
						'A listing tagged {{kw1}} tells you which delivery to expect before you open it.',
						'{{intent_clarity_2}}',
					],
				],
			],
			'browse_listings' => [
				'default' => [
					[
						[ 'Model listings and video listings answer different questions here: the model pages introduce {{subject}} performers, while the video pages show finished {{subject}} clips.', 'The two sides split the work — model pages for who is performing, video pages for what a finished session looks like — and switching between them costs a single click.' ],
						'Lead with whichever narrows the field fastest: a performer in mind points at the {{models_link}}, a mood in mind points at the {{videos_link}}, and either entry point works across {{model_scale}}.',
						'Opening {{kw1}} from either side lands you on a page that states the specifics you refine by.',
					],
					[
						[ 'Two routes into the {{subject}} field sit side by side: the performer pages for who is on, and the clip pages for what a session looks like once finished.', 'You can browse {{subject}} by performer or by clip, and the page keeps both a click apart so a wrong first turn is cheap to correct.' ],
						'Point at the {{models_link}} when a specific performer is the goal, or the {{videos_link}} when a mood is, and let {{model_scale}} carry the rest.',
						'A {{kw1}} listing on either side opens onto the detail that actually narrows your choice.',
					],
					[
						[ 'Browsing {{subject}} works in two passes: skim the performer pages for presence, then the clips for finished sessions, or reverse it depending on the visit.', 'The {{subject}} listings divide into live performers and recorded clips, and reading them as two views rather than one long list keeps the field manageable.' ],
						'The {{models_link}} covers the performer side and the {{videos_link}} the recorded side, so {{model_scale}} stays easy to move through.',
						'Follow {{kw1}} into whichever view fits and the page states what separates it from the next.',
					],
					[
						[ 'The {{subject}} field arrives as performers and as clips, so decide which question you are answering — who is on, or what a session looks like — and start there.', 'Approach {{subject}} from the performer side or the clip side; the page holds both and moving between them is a single step.' ],
						'Head to the {{models_link}} for performers or the {{videos_link}} for finished clips, with {{model_scale}} to move through on either route.',
						'A {{kw1}} listing sits on whichever side you choose and opens onto its own specifics.',
					],
				],
			],
			'compare_profiles' => [
				'default' => [
					[
						[ 'Comparing {{subject}} listings works best in pairs: open two that share the trait, then let the stated details — {{descriptor}} emphasis, schedule, and presentation — narrow the choice between them.', 'Pairwise comparison suits a shared-trait label, because two open pages show their individual differences more clearly than a run of single visits.' ],
						'Once the broad {{subject}} theme has gathered the candidates, the choosing runs on specifics, so refine by what each performer page actually states rather than the label they have in common.',
						'Line up {{kw1}} against a close alternative and the more specific page usually wins.',
					],
					[
						[ 'To choose within {{subject}}, set two candidates side by side: the shared trait is a given, so the decision rides on {{descriptor}} emphasis, timing, and how each page presents itself.', 'Two {{subject}} pages open together reveal their differences faster than visiting each alone, since the label they share drops out of the comparison.' ],
						'The trait narrows the field to candidates; the specifics each page states are what carry the final pick, so weigh those rather than the common label.',
						'Put {{kw1}} next to its nearest alternative and the page with the fuller detail tends to earn the click.',
					],
					[
						[ 'A useful {{subject}} comparison starts from what the pages state, not the label: line up two, and let presentation, {{descriptor}} emphasis, and schedule settle it.', 'Because every listing here shares the {{subject}} trait, the comparison that matters is between their specifics, which is clearest with two pages open at once.' ],
						'Let the broad trait do the gathering and the stated details do the deciding, so a pair of open {{subject}} pages refines faster than a long single-file scan.',
						'Hold {{kw1}} against a similar listing and the more specific of the two usually settles the choice.',
					],
				],
			],
			'live_vs_recorded' => [
				'default' => [
					[
						[ 'The {{subject}} theme runs across both live rooms and recorded clips, so a live session and a saved video can carry the same look at a very different pace.', 'Live and recorded are two routes to the same {{subject}} theme: one is immediate presence, the other is on-demand browsing you control.' ],
						'Pick the format by how you want to engage — a live room for real-time presence, a recorded clip for timing you set — and the page keeps both within reach.',
						'A {{kw1}} listing may appear on either the live or the recorded side.',
					],
					[
						[ 'You will find {{subject}} in two forms here: live rooms happening now and clips already recorded, each suiting a different kind of visit.', 'Whether you want a {{subject}} session in real time or a video to browse at your own pace, both sit on this page.' ],
						'Real-time presence and on-demand timing are the trade-off, so choose the live side for immediacy and the recorded side for control.',
						'The {{kw1}} theme shows up on both the live and recorded sides of the page.',
					],
					[
						[ 'Live and recorded {{subject}} answer different moods: a room to join now, or a clip to watch when it suits you.', 'The same {{subject}} look spans real-time rooms and saved clips, and which you pick shapes the pace more than anything else.' ],
						'Weigh immediacy against timing you control, and let that decide between a live {{subject}} room and a recorded one.',
						'A {{kw1}} listing can sit on either side, so check which format it is before opening.',
					],
				],
			],
			'discovery_advice' => [
				'default' => [
					[
						[ 'If a {{subject}} listing states little, treat the clips or the destination page as the introduction rather than passing over it, since the detail and wording a performer uses vary widely.', 'Sparse {{subject}} pages deserve a second look rather than a skip: how much wording or how polished a thumbnail a page carries says little about the session behind it.' ],
						'Weigh the visual detail that exists without holding silence against a page, and let the {{subject}} listings whose style and wording state the most anchor and narrow your shortlist.',
						'A thin-looking {{kw1}} page can still open onto a full session once you follow it.',
						'{{intent_clarity_3}}',
					],
					[
						[ 'A quiet {{subject}} page is not an empty one: the clips and the destination often introduce a performer the listing text does not, so read those before moving on.', 'Some {{subject}} performers write little and let the images or the room speak, so judge a sparse page by what it shows rather than what it says.' ],
						'Let the pages whose wording and style say the most anchor your shortlist, and treat a bare listing as unproven rather than weak.',
						'Even a spare {{kw1}} page can lead to a full session once you follow the link.',
						'{{intent_clarity_3}}',
					],
					[
						[ 'How much a {{subject}} listing states varies widely, so a thin page is a reason to look closer at its clips, not to skip it.', 'Detail is uneven across {{subject}} performers; the wording on a page reflects the performer\'s habits more than the session behind it.' ],
						'Use the fuller pages to anchor and narrow your shortlist, and give the sparse ones the benefit of a quick look before deciding.',
						'A {{kw1}} listing that reads thin can still open onto something complete.',
						'{{intent_clarity_3}}',
					],
				],
			],
			'variations' => [
				'trait' => [
					[
						[ 'Within {{subject}}, expect variations in {{descriptor}} emphasis and intensity, so the trait gathers the field while the details on each page separate one listing from the next.', 'The {{subject}} theme spans a range rather than one look, and the {{descriptor}} cues on each listing mark where a performer sits inside it.' ],
						'Use those variations to narrow: the shared trait is the broad anchor, and the individual differences are what refine a wide field down to a match.',
						'A listing carrying {{kw1}} sits at one specific point in that range.',
					],
				],
				'access' => [
					[
						'The {{subject}} listings vary in exactly what is open before payment, so read each destination\'s own terms rather than assuming the free boundary sits in the same place twice.',
						'Some rooms show more in public and gate the rest; others open a paid tier quickly, so treat cost and open viewing as per-listing details rather than a category rule.',
						'A {{kw1}} listing states where its own free access ends.',
					],
				],
				'format' => [
					[
						'The {{subject}} listings vary by delivery — live rooms feel immediate, recorded clips let you browse on your own timing — and format is the main axis of difference here.',
						'Read each listing for its delivery first, since that decides presence and pace more than any other variation.',
						'A {{kw1}} listing tells you which format it uses.',
					],
				],
			],
			'availability' => [
				'default' => [
					[
						'Availability across {{subject}} shifts through the day, so an empty-looking moment is usually timing rather than the end of the listings.',
						'There is {{model_scale}} of {{subject}} performers here, and they fill in at different hours, so a quiet scan now can look very different later.',
					],
					[
						'The {{subject}} listings ebb and fill through the day, so a thin moment usually means the hour, not the end of the field.',
						'With {{model_scale}} of {{subject}} performers rotating across different hours, checking again later often changes what is on.',
					],
				],
			],
			'related_navigation' => [
				'default' => [
					[
						[ 'If the {{subject}} theme is close but not exact, the {{models_link}} opens the wider field and the {{videos_link}} gathers the recorded side, so a wider reset is always one click away.', 'When you want to widen out from {{subject}}, the {{models_link}} covers every theme at once and the {{videos_link}} holds the recorded side of the whole site.' ],
						'A nearby theme like {{related_1_link}} works the adjacent ground when {{subject}} is only slightly off.',
					],
					[
						[ 'When {{subject}} is nearly right but not quite, step out through the {{models_link}} for the full field or the {{videos_link}} for every recorded clip.', 'Widen beyond {{subject}} with the {{models_link}} across every theme, or the {{videos_link}} for the whole recorded side in one place.' ],
						'For adjacent ground, {{related_1_link}} sits close to {{subject}} and may hold the nearer match.',
					],
				],
			],
			'closing' => [
				'default' => [
					[
						'Once a {{subject}} shortlist forms, the individual pages settle the rest: compare what each performer or clip states and follow the strongest {{subject}} match.',
						'The label did the gathering; the individual pages do the deciding, which is why the specifics on each page matter more than the trait they share.',
					],
					[
						'After the {{subject}} field narrows to a shortlist, let the individual pages decide it by comparing what each one states.',
						'Gathering was the label\'s job and deciding is the pages\' job, so the stated specifics outweigh the shared {{subject}} trait at the finish.',
					],
					[
						'When two or three {{subject}} listings stand out, open them together and let the details each one states pick the winner.',
						'This page narrowed the directory to the {{subject}} theme; from here the performer and clip pages carry the decision.',
					],
					[
						'End where the specifics are: with a short {{subject}} list in hand, the deciding detail lives on each performer or clip page, not on this one.',
						'The {{subject}} label brought these listings together, and what each page states about itself is what tells them apart.',
					],
				],
			],
			'public_vs_private' => [
				'access' => [
					[
						'Anything involving payment on a {{subject}} listing belongs on the destination platform, whose own labels state the real cost — this page groups the {{subject}} options but never sets their prices.',
						'Public browsing is free to start and easy to back out of, so a doubtful room costs nothing to leave before any paid tier begins.',
					],
					[
						'Cost on a {{subject}} listing is the destination\'s to set, not this page\'s: the labels and terms on the platform each one leads to are the ones to trust.',
						'Open viewing is where you judge a room for free, and stepping back before any paid tier is always an option.',
					],
				],
				'default' => [
					[
						'Where a {{subject}} session moves from open browsing to anything paid is set by the destination, not by this page, so the platform each listing leads to is the terms to trust.',
						'Confirm cost and access on the destination itself, since the free and paid line is theirs to draw.',
					],
					[
						'The point where {{subject}} browsing turns paid lives on the destination platform, so read its terms rather than assuming a boundary from this page.',
						'This directory groups the listings; the platform each one opens sets what is free and what is not.',
					],
				],
			],
			'platform_links' => [
				'default' => [
					[
						'Each {{subject}} listing hands off to the platform that hosts the room, and the destination shapes the session because its features travel with the outbound click.',
						'Check the destination page first to confirm the features you want exist there, since what a {{subject}} listing promises here is delivered on that platform, not on this directory.',
						'A {{kw1}} listing is a pointer; the room it opens is where the session actually runs.',
					],
					[
						'Every {{subject}} listing points outward to the platform that runs the room, so the features and terms you care about live there rather than on this page.',
						'Confirm on the destination that it carries what you want before committing, because the outbound click is where the session really begins.',
						'Treat a {{kw1}} entry as a signpost to the hosting platform, not the session itself.',
					],
				],
			],
			'privacy_safety' => [
				'default' => [
					[
						'Confirm the performer name before anything else on a {{subject}} listing, since similar names span platforms and a wrong-page detour is the common mix-up.',
						'Keep anything sensitive — payments especially — on the destination platform\'s own pages, where the {{subject}} listing actually leads, rather than anywhere this directory could be imitated.',
						'Backing out of a doubtful {{subject}} destination is free, and the listing stays here for another approach.',
					],
					[
						'Names repeat across platforms, so match the {{subject}} performer name carefully before you follow a listing out.',
						'Anything involving payment stays on the destination\'s own pages; this directory only points to the {{subject}} rooms, it never handles a transaction.',
						'If a {{subject}} destination feels off, leaving costs nothing and the listing remains for a second try.',
					],
				],
			],
			'first_time' => [
				'default' => [
					[
						'New to the {{subject}} listings? Confirm the performer name first, since similar names span platforms, then let the {{subject}} pages that state the most detail lead the way.',
						'Start broad and refine: the trait narrows the whole directory to this theme, and the individual pages take it from there.',
					],
					[
						'First visit to {{subject}}? Check the performer name against the destination, then follow the pages whose stated detail is fullest.',
						'Begin wide and let the {{subject}} trait narrow the field, then refine down to a single page from there.',
					],
				],
			],
			'returning_visitors' => [
				'default' => [
					[
						'Coming back to {{subject}}? The listings refresh as performers and clips are added, so the {{subject}} field you compared last time will have moved on.',
						'Re-scan before you settle, since a wider or narrower shortlist may have opened up since the last visit.',
					],
					[
						'Returning to {{subject}}? New performers and clips arrive between visits, so the field is rarely the same twice.',
						'A quick fresh scan is worth it, because the {{subject}} shortlist you built before may have shifted.',
					],
				],
			],
		];
		return $banks[ $section ] ?? [];
	}

	private static function heading_bank(): array {
		return [
			'intro'              => [ 'default' => [ 'What {{subject_title}} Covers Here', 'Inside the {{subject_title}} Listings', 'Where {{subject_title}} Browsing Starts' ] ],
			'expectations'       => [ 'default' => [ 'What to Expect Across {{subject_title}}', 'How the {{subject_title}} Listings Vary', 'The {{subject_title}} Label, and Its Limits' ] ],
			'browse_listings'    => [ 'default' => [ 'Browsing {{subject_title}} Models and Clips', 'Finding Your Way Around {{subject_title}}', 'Two Ways Into the {{subject_title}} Field' ] ],
			'compare_profiles'   => [ 'default' => [ 'Comparing {{subject_title}} Performers', 'Choosing Between {{subject_title}} Listings', 'Deciding Within {{subject_title}}' ] ],
			'live_vs_recorded'   => [ 'default' => [ 'Live and Recorded {{subject_title}}', '{{subject_title}} Rooms and Saved Clips', '{{subject_title}} in Real Time or On Demand' ] ],
			'discovery_advice'   => [ 'default' => [ 'Reading Sparse {{subject_title}} Pages', 'Getting More From {{subject_title}} Listings', 'When a {{subject_title}} Page Says Little' ] ],
			'variations'         => [ 'default' => [ 'Variations Within {{subject_title}}', 'The Range of {{subject_title}} Listings', 'How {{subject_title}} Listings Differ' ] ],
			'availability'       => [ 'default' => [ 'When {{subject_title}} Listings Are Busiest', '{{subject_title}} Availability Through the Day', 'Timing Your {{subject_title}} Visit' ] ],
			'related_navigation' => [ 'default' => [ 'Beyond {{subject_title}}: Nearby Themes', 'Widening Out From {{subject_title}}', 'If {{subject_title}} Is Not Quite It' ] ],
			'public_vs_private'  => [ 'default' => [ 'Access and Pricing for {{subject_title}}', 'Where {{subject_title}} Sessions Are Set', 'Free and Paid Across {{subject_title}}' ] ],
			'platform_links'     => [ 'default' => [ 'Where {{subject_title}} Listings Lead', 'The Platform Behind Each {{subject_title}} Listing', 'Following a {{subject_title}} Listing Out' ] ],
			'privacy_safety'     => [ 'default' => [ 'Staying Safe in {{subject_title}}', 'Confirming a {{subject_title}} Listing', 'Before You Follow a {{subject_title}} Link' ] ],
			'first_time'         => [ 'default' => [ 'First Time in {{subject_title}}', 'Starting With {{subject_title}}', 'New to {{subject_title}}?' ] ],
			'returning_visitors' => [ 'default' => [ 'Coming Back to {{subject_title}}', 'What Changed in {{subject_title}}', 'Returning to {{subject_title}}' ] ],
		];
	}
}
