import { useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { api, DashboardStats } from '../api';

/**
 * Eval Dashboard: the plugin's core differentiator. Surfaces acceptance
 * rate, edit distance, and keyword coverage broken down by prompt version,
 * so a store owner (or a hiring engineer reviewing this project) can see
 * this isn't generate-and-forget — every draft is measured.
 */
export default function EvalDashboard() {
	const [ stats, setStats ] = useState< DashboardStats | null >( null );
	const [ loading, setLoading ] = useState( true );

	useEffect( () => {
		api.dashboard()
			.then( setStats )
			.finally( () => setLoading( false ) );
	}, [] );

	if ( loading ) {
		return <p>{ __( 'Loading eval data…', 'woocopy-ai' ) }</p>;
	}

	if ( ! stats || stats.total === 0 ) {
		return (
			<div className="woocopy-card">
				<p className="woocopy-muted">
					{ __( 'No generations logged yet. Generate some copy to populate this dashboard.', 'woocopy-ai' ) }
				</p>
			</div>
		);
	}

	const statusCounts = Object.values( stats.by_status );
	const acceptedTotal =
		( stats.by_status.accepted?.count || 0 ) + ( stats.by_status.edited?.count || 0 );
	const acceptanceRate = stats.total > 0 ? Math.round( ( acceptedTotal / stats.total ) * 100 ) : 0;

	return (
		<div className="woocopy-eval-dashboard">
			<div className="woocopy-stat-row">
				<div className="woocopy-stat-card">
					<div className="woocopy-stat-card__value">{ stats.total }</div>
					<div className="woocopy-stat-card__label">{ __( 'Total generations', 'woocopy-ai' ) }</div>
				</div>
				<div className="woocopy-stat-card">
					<div className="woocopy-stat-card__value">{ acceptanceRate }%</div>
					<div className="woocopy-stat-card__label">{ __( 'Accepted (as-is or edited)', 'woocopy-ai' ) }</div>
				</div>
				<div className="woocopy-stat-card">
					<div className="woocopy-stat-card__value">{ stats.by_status.rejected?.count || 0 }</div>
					<div className="woocopy-stat-card__label">{ __( 'Rejected', 'woocopy-ai' ) }</div>
				</div>
			</div>

			<div className="woocopy-card">
				<h2>{ __( 'By status', 'woocopy-ai' ) }</h2>
				<table className="woocopy-table">
					<thead>
						<tr>
							<th>{ __( 'Status', 'woocopy-ai' ) }</th>
							<th>{ __( 'Count', 'woocopy-ai' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ statusCounts.map( ( s ) => (
							<tr key={ s.status }>
								<td>{ s.status }</td>
								<td>{ s.count }</td>
							</tr>
						) ) }
					</tbody>
				</table>
			</div>

			<div className="woocopy-card">
				<h2>{ __( 'By prompt version', 'woocopy-ai' ) }</h2>
				<p className="woocopy-muted">
					{ __(
						'Compare acceptance rate and average edit distance across prompt iterations — this is how you know a prompt change actually helped.',
						'woocopy-ai'
					) }
				</p>
				<table className="woocopy-table">
					<thead>
						<tr>
							<th>{ __( 'Version', 'woocopy-ai' ) }</th>
							<th>{ __( 'Total', 'woocopy-ai' ) }</th>
							<th>{ __( 'Accepted', 'woocopy-ai' ) }</th>
							<th>{ __( 'Edited', 'woocopy-ai' ) }</th>
							<th>{ __( 'Rejected', 'woocopy-ai' ) }</th>
							<th>{ __( 'Avg. edit distance', 'woocopy-ai' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ stats.by_prompt_version.map( ( row ) => (
							<tr key={ row.prompt_version }>
								<td>{ row.prompt_version }</td>
								<td>{ row.total }</td>
								<td>{ row.accepted }</td>
								<td>{ row.edited }</td>
								<td>{ row.rejected }</td>
								<td>
									{ row.avg_edit_distance_long !== null
										? Math.round( row.avg_edit_distance_long )
										: '—' }
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			</div>

			<div className="woocopy-card">
				<h2>{ __( 'Recent generations', 'woocopy-ai' ) }</h2>
				<table className="woocopy-table">
					<thead>
						<tr>
							<th>{ __( 'Product ID', 'woocopy-ai' ) }</th>
							<th>{ __( 'Prompt version', 'woocopy-ai' ) }</th>
							<th>{ __( 'Status', 'woocopy-ai' ) }</th>
							<th>{ __( 'Keyword coverage', 'woocopy-ai' ) }</th>
							<th>{ __( 'Created', 'woocopy-ai' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ stats.recent.map( ( row ) => {
							let coverage = '—';
							try {
								const parsed = JSON.parse( row.rubric_scores );
								if ( parsed.keyword_coverage !== null && parsed.keyword_coverage !== undefined ) {
									coverage = `${ Math.round( parsed.keyword_coverage * 100 ) }%`;
								}
							} catch ( e ) {
								// Leave as em dash if rubric_scores wasn't valid JSON.
							}
							return (
								<tr key={ row.id }>
									<td>{ row.product_id }</td>
									<td>{ row.prompt_version }</td>
									<td>{ row.status }</td>
									<td>{ coverage }</td>
									<td>{ row.created_at }</td>
								</tr>
							);
						} ) }
					</tbody>
				</table>
			</div>
		</div>
	);
}
