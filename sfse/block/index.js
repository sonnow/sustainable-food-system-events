import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps } from '@wordpress/block-editor';
import './editor.css';
import metadata from './block.json';

registerBlockType( metadata.name, {
    edit: function Edit() {
        const blockProps = useBlockProps( { className: 'sfse-editor-preview' } );

        return (
            <div { ...blockProps }>
                <div className="sfse-editor-label">
                    <span className="sfse-editor-icon">📅</span>
                    Sustainable Food System Events
                </div>

                {/* Skeleton filter bar */}
                <div className="sfse-skeleton-filters">
                    <div className="sfse-skeleton-row">
                        <div className="sfse-skeleton sfse-skeleton-select" />
                        <div className="sfse-skeleton-date-group">
                            <div className="sfse-skeleton sfse-skeleton-preset" />
                            <div className="sfse-skeleton sfse-skeleton-preset" />
                            <div className="sfse-skeleton sfse-skeleton-preset" />
                        </div>
                        <div className="sfse-skeleton sfse-skeleton-btn" />
                    </div>
                    <div className="sfse-skeleton sfse-skeleton-advanced" />
                </div>

                {/* Skeleton card grid */}
                <div className="sfse-skeleton-grid">
                    { [1, 2, 3, 4, 5, 6].map( ( i ) => (
                        <div key={ i } className="sfse-skeleton-card">
                            <div className="sfse-skeleton-card-header">
                                <div className="sfse-skeleton sfse-skeleton-date-line" />
                                <div className="sfse-skeleton sfse-skeleton-title" />
                                <div className="sfse-skeleton sfse-skeleton-title sfse-skeleton-title-short" />
                            </div>
                            <div className="sfse-skeleton-card-body">
                                <div className="sfse-skeleton sfse-skeleton-organiser" />
                                <div className="sfse-skeleton sfse-skeleton-text" />
                                <div className="sfse-skeleton sfse-skeleton-text" />
                                <div className="sfse-skeleton sfse-skeleton-text sfse-skeleton-text-short" />
                                <div className="sfse-skeleton-tags">
                                    <div className="sfse-skeleton sfse-skeleton-tag" />
                                    <div className="sfse-skeleton sfse-skeleton-tag" />
                                    <div className="sfse-skeleton sfse-skeleton-tag sfse-skeleton-tag-wide" />
                                </div>
                                <div className="sfse-skeleton-badges">
                                    <div className="sfse-skeleton sfse-skeleton-badge" />
                                    <div className="sfse-skeleton sfse-skeleton-badge sfse-skeleton-badge-wide" />
                                    <div className="sfse-skeleton sfse-skeleton-badge" />
                                </div>
                            </div>
                            <div className="sfse-skeleton-card-footer">
                                <div className="sfse-skeleton sfse-skeleton-link" />
                                <div className="sfse-skeleton sfse-skeleton-link" />
                            </div>
                        </div>
                    ) ) }
                </div>
            </div>
        );
    },

    // No save — server-side rendered
    save: function() {
        return null;
    },
} );
