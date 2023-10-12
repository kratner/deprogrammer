import React from 'react';

interface MediaRendererControlProps {
  currentMediaIndex: number;
  totalMediaCount: number;
  onPrevMedia?: () => void;
  onNextMedia?: () => void;
  className?: string;
}

const MediaRendererControl: React.FC<MediaRendererControlProps> = ({
  currentMediaIndex,
  totalMediaCount,
  onPrevMedia,
  onNextMedia,
  className
}) => {
  return (
    <div className={className && className}>
      <button onClick={onPrevMedia} disabled={currentMediaIndex === 0}>
        Previous
      </button>
      <span>{`${currentMediaIndex + 1} / ${totalMediaCount}`}</span>
      <button onClick={onNextMedia} disabled={currentMediaIndex === totalMediaCount - 1}>
        Next
      </button>
    </div>
  );
};

export default MediaRendererControl;
